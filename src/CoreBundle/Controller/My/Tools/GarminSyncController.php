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
        (new Filesystem())->mkdir($importDir);

        $since = $this->lastSyncEpoch($account);

        $process = new Process([
            $this->getParameter('python3_path'),
            $this->scriptPath(),
            'sync',
            $this->sessionDir($account),
            (string)$since,
            $importDir,
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

        if (!empty($downloaded)) {
            $importProcess = new Process([
                PHP_BINARY,
                $this->getParameter('kernel.root_dir').'/../bin/console',
                'runalyze:activity:bulk-import',
                $account->getUsername(),
                $importDir,
                '--env='.$this->getParameter('kernel.environment'),
            ]);
            $importProcess->setTimeout(600);

            try {
                $importProcess->run();
                $importFailed = !$importProcess->isSuccessful();
                $importError = trim($importProcess->getErrorOutput()) ?: trim($importProcess->getOutput());
            } catch (\Throwable $e) {
                $importFailed = true;
                $importError = $e->getMessage();
            }

            // The import copies files into data/import/ itself, so the
            // freshly downloaded copies here are no longer needed either way.
            (new Filesystem())->remove($importDir);

            if ($importFailed) {
                $this->addFlash('error', $this->get('translator')->trans('Activities were downloaded from Garmin but the import failed: %reason%', [
                    '%reason%' => $importError,
                ]));

                return $this->redirectToRoute('tools-garmin-sync');
            }
        }

        if (isset($result['latest_epoch'])) {
            $this->confRepository()->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_LAST_SYNC, (string)$result['latest_epoch']);
        }

        if (!empty($result['errors'])) {
            $this->addFlash('error', $this->get('translator')->trans('%count% activities could not be downloaded, see server log for details.', [
                '%count%' => count($result['errors']),
            ]));
        }

        $this->addFlash('success', $this->get('translator')->trans('%count% new activities imported from Garmin Connect.', [
            '%count%' => count($downloaded),
        ]));

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
