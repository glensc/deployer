<?php declare(strict_types=1);
/* (c) Anton Medvedev <anton@medv.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer\Exception;

use Throwable;

class Exception extends \Exception
{
    private static $recipeFile;
    private $filename;
    private $lineNumber;

    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        if (function_exists('debug_backtrace')) {
            $trace = debug_backtrace();
            foreach ($trace as $t) {
                if (!empty($t['file']) && $t['file'] === self::$recipeFile) {
                    $this->filename = basename($t['file']);
                    $this->lineNumber = $t['line'];
                    break;
                }
            }
        }
        parent::__construct($message, $code, $previous);
    }

    public static function await()
    {
        if (function_exists('debug_backtrace')) {
            $trace = debug_backtrace();
            self::$recipeFile = $trace[1]['file'];
        }
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function getLineNumber()
    {
        return $this->lineNumber;
    }
}

