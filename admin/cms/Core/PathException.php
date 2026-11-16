<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/** Exceção de caminho inseguro ou inexistente. */
class PathException extends \RuntimeException
{
}
