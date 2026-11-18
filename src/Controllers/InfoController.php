<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http\Request;
use App\Http\Response;

final class InfoController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function info(Request $request): never
    {
        Response::json([
            'version' => $this->config->get('APP_VERSION', '1.0.0'),
            'status' => 'ok',
        ]);
    }
}
