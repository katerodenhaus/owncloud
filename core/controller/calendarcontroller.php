<?php
/**
 * @author    Lukas Reschke <lukas@owncloud.com>
 * @author    Morris Jobke <hey@morrisjobke.de>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license   AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Core\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\IRequest;

class CalendarController extends Controller
{
    /**
     * @var IConfig
     */
    private $config;
    /**
     * @var IUserSession
     */
    private $userSession;

    /**
     * @param string       $appName
     * @param IRequest     $request an instance of the request
     * @param IUserSession $userSession
     * @param IConfig      $config
     */
    public function __construct($appName, IRequest $request, IConfig $config)
    {
        parent::__construct($appName, $request);
        $this->config      = $config;
        $this->userSession = $userSession;
    }

    /**
     * get a config value
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getCalendars()
    {
        return new JSONResponse([
                                    'value' => 'stupid',
                                ]);
    }
}
