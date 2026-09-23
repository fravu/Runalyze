<?php

namespace Runalyze\Bundle\CoreBundle\Controller\My\Tools;

use Runalyze\Bundle\CoreBundle\Entity\Account;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
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
     * Moves any downloaded .fit files from the temporary per-account Garmin
     * download folder into the shared data/import/ folder that the normal
     * browser upload also uses, so the same file-picker/preview page
     * (activity-upload) can be used instead of forcing an all-or-nothing
     * import. Using that page also means each activity is parsed and
     * imported individually - a single activity with bad/out-of-range data
     * shows as one error there instead of, as the bulk-import console
     * command does, aborting the entire batch on the first failure.
     *
     * @return string[] filenames (relative to data/import/) moved
     */
    private function moveIntoImportQueue($garminDownloadDir)
    {
        $fitFiles = glob($garminDownloadDir.'/*.fit');

        if (empty($fitFiles)) {
            return [];
        }

        $fs = new Filesystem();
        $targetDir = $this->getParameter('data_directory').'/import';
        $fs->mkdir($targetDir);

        $filenames = [];

        foreach ($fitFiles as $file) {
            $basename = basename($file);
            $fs->rename($file, $targetDir.'/'.$basename, true);
            $filenames[] = $basename;
        }

        return $filenames;
    }

    /**
     * Returns JSON rather than redirecting: activity-upload and everything
     * it can lead to (the multi editor) are built to be loaded via AJAX
     * into the app's already-open overlay, not visited as a standalone
     * page - their own JavaScript assumes that overlay (and the objects
     * Runalyze.Overlay.init() sets up for it) already exists, which is
     * only true if this page's own script hands off to it with
     * $("#ajax").loadDiv(...), the same way the regular multi-file browser
     * upload does. A plain HTTP redirect instead lands the user on a
     * broken, un-navigable page.
     *
     * @Route("/my/tools/garmin-sync/run", name="tools-garmin-sync-run")
     * @Security("has_role('ROLE_USER')")
     */
    public function runAction(Account $account)
    {
        $trans = $this->get('translator');

        if (!$this->isConnected($account)) {
            return new JsonResponse(['error' => $trans->trans('Please connect your Garmin Connect account first.')]);
        }

        $downloadDir = $this->getParameter('data_directory').'/import/garmin_sync/'.$account->getId();

        // Pick up files from a previous run that downloaded fine but never
        // made it into the picker (e.g. the process was killed by a
        // timeout right after finishing downloads) before fetching
        // anything new, so nothing already downloaded is ever lost.
        $pending = $this->moveIntoImportQueue($downloadDir);
        $info = null;

        if (empty($pending)) {
            (new Filesystem())->mkdir($downloadDir);

            $since = $this->lastSyncEpoch($account);

            $process = new Process([
                $this->getParameter('python3_path'),
                $this->scriptPath(),
                'sync',
                $this->sessionDir($account),
                (string)$since,
                $downloadDir,
                (string)self::MAX_ACTIVITIES_PER_RUN,
            ]);
            $process->setTimeout(300);
            $result = $this->runProcess($process);

            if ('ok' !== $result['status']) {
                return new JsonResponse(['error' => $trans->trans('Garmin sync failed: %reason%', [
                    '%reason%' => $result['message'] ?? 'unknown error',
                ])]);
            }

            $pending = $this->moveIntoImportQueue($downloadDir);

            if (isset($result['resume_epoch'])) {
                // This advances as soon as activities are downloaded from
                // Garmin, regardless of which ones the user then actually
                // picks on the next page - otherwise skipped activities
                // would keep reappearing on every future sync.
                $this->confRepository()->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_LAST_SYNC, (string)$result['resume_epoch']);
            }

            $messages = [];

            if (!empty($result['errors'])) {
                $messages[] = $trans->trans('%count% activities could not be downloaded, see server log for details.', [
                    '%count%' => count($result['errors']),
                ]);
            }

            if (!empty($result['more_available'])) {
                $messages[] = $trans->trans('There are more activities left to sync (capped at %max% per click) - click "Sync now" again once you are done here to continue.', [
                    '%max%' => self::MAX_ACTIVITIES_PER_RUN,
                ]);
            }

            $info = empty($messages) ? null : implode(' ', $messages);
        }

        if (empty($pending)) {
            return new JsonResponse([
                'success' => true,
                'files' => [],
                'message' => $trans->trans('No new activities found on Garmin Connect.'),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'files' => $pending,
            'info' => $info,
        ]);
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
