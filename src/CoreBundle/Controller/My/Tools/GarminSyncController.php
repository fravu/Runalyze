<?php

namespace Runalyze\Bundle\CoreBundle\Controller\My\Tools;

use Runalyze\Bundle\CoreBundle\Entity\Account;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Manual "sync now" against Garmin Connect.
 *
 * This talks to Garmin Connect through the unofficial `garminconnect`
 * Python package (same login as the Garmin Connect app/website), not an
 * official Garmin API - Garmin can change their internal login/API
 * behaviour at any time, which would break this until that package is
 * updated upstream.
 */
class GarminSyncController extends Controller
{
    const CONF_CATEGORY = 'garmin_sync';
    const CONF_LAST_SYNC = 'GARMIN_LAST_SYNC_EPOCH';

    // Cap how many activities a single click fetches. Garmin's own
    // rate-limiting and the process timeout below both make "just download
    // everything since the beginning of time" a bad idea for accounts with
    // a large history - the user clicks "sync now" repeatedly instead.
    const MAX_ACTIVITIES_PER_RUN = 100;

    /**
     * @return string
     */
    private function sessionDir(Account $account)
    {
        return $this->getParameter('data_directory').'/garmin_session/'.$account->getId();
    }

    /**
     * @return string
     */
    private function scriptPath()
    {
        return $this->getParameter('kernel.root_dir').'/../call/python/garmin_sync.py';
    }

    /**
     * PHP_BINARY can be an empty string on some server setups (seen with
     * some PHP-FPM configurations), which makes Process silently try to
     * run an empty command ("exec: : Permission denied"). Fall back to
     * common CLI paths, and finally to a bare "php" that relies on PATH,
     * rather than trusting PHP_BINARY blindly.
     *
     * @return string
     */
    private function phpCliBinary()
    {
        $fs = new Filesystem();

        foreach ([PHP_BINARY, '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if ('' !== $candidate && $fs->exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /**
     * @return \Runalyze\Bundle\CoreBundle\Entity\ConfRepository
     */
    private function confRepository()
    {
        return $this->getDoctrine()->getRepository('CoreBundle:Conf');
    }

    private function isConnected(Account $account)
    {
        $fs = new Filesystem();
        $dir = $this->sessionDir($account);

        // Different versions/forks of the underlying Python package store
        // session tokens under different filenames - check for either.
        return $fs->exists($dir.'/garmin_tokens.json') || $fs->exists($dir.'/oauth2_token.json');
    }

    private function lastSyncEpoch(Account $account)
    {
        $conf = $this->confRepository()->findByAccountAndKey($account, self::CONF_LAST_SYNC);

        return null === $conf ? 0 : (int)$conf->getValue();
    }

    /**
     * Runs a process and always returns a decoded JSON result array, even
     * if the process crashes, times out, or prints garbage - the Garmin
     * side of this can fail in ways outside our control (dead network,
     * Garmin changing something, ...) and none of that should ever surface
     * as an uncaught exception / 500 page.
     *
     * @return array{status: string, message?: string, [key: string]: mixed}
     */
    private function runProcess(Process $process)
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return ['status' => 'error', 'message' => 'timed out (Garmin Connect not reachable or too slow to respond)'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $result = json_decode($process->getOutput(), true);

        if (!$process->isSuccessful() || !is_array($result) || !isset($result['status'])) {
            return ['status' => 'error', 'message' => trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'unknown error'];
        }

        return $result;
    }

    /**
     * @Route("/my/tools/garmin-sync", name="tools-garmin-sync")
     * @Security("has_role('ROLE_USER')")
     */
    public function overviewAction(Request $request, Account $account)
    {
        $error = null;

        if ($request->isMethod('POST')) {
            $email = trim((string)$request->request->get('garmin_email', ''));
            $password = (string)$request->request->get('garmin_password', '');

            if ('' === $email || '' === $password) {
                $error = $this->get('translator')->trans('Please enter both your Garmin Connect email and password.');
            } else {
                $error = $this->login($account, $email, $password);
            }
        }

        return $this->render('tools/garmin_sync/base.html.twig', [
            'connected' => $this->isConnected($account),
            'lastSync' => $this->lastSyncEpoch($account),
            'error' => $error,
        ]);
    }

    /**
     * @Route("/my/tools/garmin-sync/disconnect", name="tools-garmin-sync-disconnect")
     * @Security("has_role('ROLE_USER')")
     */
    public function disconnectAction(Account $account)
    {
        (new Filesystem())->remove($this->sessionDir($account));

        return $this->redirectToRoute('tools-garmin-sync');
    }

    /**
     * Lets the user skip straight to a given date instead of syncing their
     * complete Garmin Connect history (which for a long-time user can be
     * thousands of activities).
     *
     * @Route("/my/tools/garmin-sync/set-since", name="tools-garmin-sync-set-since")
     * @Security("has_role('ROLE_USER')")
     */
    public function setSinceAction(Request $request, Account $account)
    {
        $date = (string)$request->request->get('since_date', '');
        $timestamp = '' !== $date ? strtotime($date.' 00:00:00 UTC') : false;

        if (false === $timestamp) {
            $this->addFlash('error', $this->get('translator')->trans('Please choose a valid date.'));
        } else {
            $this->confRepository()->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_LAST_SYNC, (string)$timestamp);
            $this->addFlash('success', $this->get('translator')->trans('Garmin sync will now only fetch activities from %date% onwards.', [
                '%date%' => date('d.m.Y', $timestamp),
            ]));
        }

        return $this->redirectToRoute('tools-garmin-sync');
    }

    /**
     * Runs the existing bulk-import command against whatever .fit files are
     * currently in $importDir (if any). On success the files are removed
     * (the import copies them into data/import/ itself). On failure they
     * are deliberately left in place so the next run's leftover-recovery
     * check can retry them instead of losing already-downloaded data.
     *
     * @return string|null error message, or null on success/nothing to do
     */
    private function importDownloadedFiles(Account $account, $importDir)
    {
        $fs = new Filesystem();

        if (!$fs->exists($importDir) || 0 === count(glob($importDir.'/*.fit'))) {
            return null;
        }

        $importProcess = new Process([
            $this->phpCliBinary(),
            $this->getParameter('kernel.root_dir').'/../bin/console',
            'runalyze:activity:bulk-import',
            $account->getUsername(),
            $importDir,
            '--env='.$this->getParameter('kernel.environment'),
        ]);
        $importProcess->setTimeout(600);

        try {
            $importProcess->run();
            $failed = !$importProcess->isSuccessful();
            $error = trim($importProcess->getErrorOutput()) ?: trim($importProcess->getOutput());
        } catch (\Throwable $e) {
            $failed = true;
            $error = $e->getMessage();
        }

        if ($failed) {
            // Leave the downloaded files in place so the next run's
            // leftover-recovery step can retry the import instead of the
            // data just being silently deleted.
            return $error;
        }

        // The import copies files into data/import/ itself, so the
        // downloaded copies here are no longer needed once it succeeded.
        $fs->remove($importDir);

        return null;
    }

    /**
     * @Route("/my/tools/garmin-sync/run", name="tools-garmin-sync-run")
     * @Security("has_role('ROLE_USER')")
     */
    public function runAction(Account $account)
    {
        if (!$this->isConnected($account)) {
            $this->addFlash('error', $this->get('translator')->trans('Please connect your Garmin Connect account first.'));

            return $this->redirectToRoute('tools-garmin-sync');
        }

        $importDir = $this->getParameter('data_directory').'/import/garmin_sync/'.$account->getId();

        // Recover files from a previous run that downloaded fine but never
        // got imported (e.g. because the process was killed by a timeout
        // right after finishing downloads) before doing anything else.
        if (null !== $leftoverError = $this->importDownloadedFiles($account, $importDir)) {
            $this->addFlash('error', $this->get('translator')->trans('Found activities downloaded by a previous sync that failed to import: %reason%', [
                '%reason%' => $leftoverError,
            ]));

            return $this->redirectToRoute('tools-garmin-sync');
        }

        (new Filesystem())->mkdir($importDir);

        $since = $this->lastSyncEpoch($account);

        $process = new Process([
            $this->getParameter('python3_path'),
            $this->scriptPath(),
            'sync',
            $this->sessionDir($account),
            (string)$since,
            $importDir,
            (string)self::MAX_ACTIVITIES_PER_RUN,
        ]);
        $process->setTimeout(300);
        $result = $this->runProcess($process);

        if ('ok' !== $result['status']) {
            $this->addFlash('error', $this->get('translator')->trans('Garmin sync failed: %reason%', [
                '%reason%' => $result['message'] ?? 'unknown error',
            ]));

            return $this->redirectToRoute('tools-garmin-sync');
        }

        $downloaded = $result['downloaded'] ?? [];

        if (null !== $importError = $this->importDownloadedFiles($account, $importDir)) {
            $this->addFlash('error', $this->get('translator')->trans('Activities were downloaded from Garmin but the import failed: %reason%', [
                '%reason%' => $importError,
            ]));

            return $this->redirectToRoute('tools-garmin-sync');
        }

        if (isset($result['resume_epoch'])) {
            $this->confRepository()->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_LAST_SYNC, (string)$result['resume_epoch']);
        }

        if (!empty($result['errors'])) {
            $this->addFlash('error', $this->get('translator')->trans('%count% activities could not be downloaded, see server log for details.', [
                '%count%' => count($result['errors']),
            ]));
        }

        $this->addFlash('success', $this->get('translator')->trans('%count% new activities imported from Garmin Connect.', [
            '%count%' => count($downloaded),
        ]));

        if (!empty($result['more_available'])) {
            $this->addFlash('info', $this->get('translator')->trans('There are more activities left to sync (capped at %max% per click) - click "Sync now" again to continue.', [
                '%max%' => self::MAX_ACTIVITIES_PER_RUN,
            ]));
        }

        return $this->redirectToRoute('tools-garmin-sync');
    }

    /**
     * @return string|null error message, or null on success
     */
    private function login(Account $account, $email, $password)
    {
        $sessionDir = $this->sessionDir($account);
        (new Filesystem())->mkdir($sessionDir);

        $process = new Process([
            $this->getParameter('python3_path'),
            $this->scriptPath(),
            'login',
            $sessionDir,
        ]);
        $process->setInput($email."\n".$password."\n");
        $process->setTimeout(60);
        $result = $this->runProcess($process);

        if ('ok' !== $result['status']) {
            return $this->get('translator')->trans('Login to Garmin Connect failed: %reason%', [
                '%reason%' => $result['message'] ?? 'unknown error',
            ]);
        }

        return null;
    }
}
