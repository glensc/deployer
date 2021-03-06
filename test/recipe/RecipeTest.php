<?php
/* (c) Anton Medvedev <anton@medv.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;

class RecipeTest extends TestCase
{
    public function textDeploy()
    {
        $console = $this->createMock(Application::class);
        $deployer = new Deployer($console);
    }
}
