<?php
/**
 * Delete channel
 */
namespace Minds\Controllers\api\v2\settings;

use Minds\Entities;
use Minds\Interfaces;

use Minds\Core\Di\Di;
use Minds\Api\Factory;
use Minds\Core;

class delete implements Interfaces\Api
{
    public function get($pages)
    {
        return Factory::response(['status'=>'error', 'message'=>'GET is not supported for this endpoint']);
    }

    public function post($pages)
    {
        $validator = Di::_()->get('Security\Password');

        if (!$validator->check(Core\Session::getLoggedinUser(), $_POST['password'])) {
            header('HTTP/1.1 401 Unauthorized', true, 401);
            return Factory::response(['status' => 'failed']);
        }

        /** @var Core\Channels\Manager $manager */
        $manager = Di::_()->get('Channels\Manager');
        $manager
            ->setUser(Core\Session::getLoggedinUser())
            ->delete();
        
        return Factory::response([]);
    }

    public function put($pages)
    {
        return Factory::response([]);
    }

    public function delete($pages)
    {
        return Factory::response([]);
    }
}
