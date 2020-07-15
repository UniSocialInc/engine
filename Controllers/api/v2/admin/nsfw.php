<?php
/**
 * Mark posts as NSFW.
 */

namespace Minds\Controllers\api\v2\admin;

use Minds\Api\Factory;
use Minds\Entities;
use Minds\Interfaces;
use Minds\Core\Entities\Actions\Save;
use Minds\Core\Di\Di;
use Minds\Entities\User;

class nsfw implements Interfaces\Api, Interfaces\ApiAdminPam
{
    /**
     * Equivalent to HTTP GET method.
     *
     * @param array $pages
     *
     * @return mixed|null
     */
    public function get($pages)
    {
        return Factory::response([]);
    }

    /**
     * Equivalent to HTTP POST method.
     *
     * @param array $pages
     *
     * @return mixed|null
     */
    public function post($pages)
    {
        $guid = intval($pages[0]);

        if (!$guid) {
            return Factory::response(['status' => 'error', 'message' => 'You must send a GUID.']);
        }

        $entity = Entities\Factory::build($guid);

        if (!$entity) {
            return Factory::response(['status' => 'error', 'message' => 'Entity not found.']);
        }

        $entity->setNsfw($_POST['nsfw']);
        $entity->setNsfwLock($_POST['nsfw']);

        $save = new Save();
        $save->setEntity($entity)
          ->save();

        /** @var Core\Events\Dispatcher $dispatcher */
        $dispatcher = Di::_()->get('EventsDispatcher');

        $dispatcher->trigger('search:index', 'all', [
            'entity' => $entity,
            'immediate' => true,
        ]);

        if ($entity->entity_guid) {
            $child = Entities\Factory::build($entity->entity_guid);
            $child->setNsfw($_POST['nsfw']);
            $child->setNsfwLock($_POST['nsfw']);

            $save->setEntity($child)
                ->save();

            $dispatcher->trigger('search:index', 'all', [
                'entity' => $child,
                'immediate' => true,
            ]);
        }

        // Remove user as stripe merchant.
        if ($entity->type === 'user' && $entity->merchant) {
            try {
                $user = new User($guid);
                $connectManager = Di::_()->get('Stripe\Connect\Manager');
                $account = $connectManager->getByUser($user);
                $connectManager->delete($account);
            } catch (\Exception $e) {
                Di::_()->get('Logger')->error('Error removing merchant '.$e);
                return Factory::response([
                    'status' => 'error',
                    'message' => 'Failed to remove merchant for stripe.'
                ]);
            }
        }

        return Factory::response([]);
    }

    /**
     * Equivalent to HTTP PUT method.
     *
     * @param array $pages
     *
     * @return mixed|null
     */
    public function put($pages)
    {
        return Factory::response([]);
    }

    /**
     * Equivalent to HTTP DELETE method.
     *
     * @param array $pages
     *
     * @return mixed|null
     */
    public function delete($pages)
    {
        return Factory::response([]);
    }
}
