<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Database;
use App\Http\Request;
use App\Http\Response;

final class InfoController
{
    public function __construct(
        private readonly Config $config,
        private readonly ?Database $db = null,
    ) {
    }

    public function info(Request $request): never
    {
        $dbStatus = 'unconfigured';
        if ($this->db !== null) {
            try {
                $this->db->pdo();
                $dbStatus = 'connected';
            } catch (\Throwable $e) {
                $dbStatus = 'error: ' . $e->getMessage();
            }
        }

        Response::json([
            'version' => $this->config->get('APP_VERSION', '1.0.0'),
            'status' => 'ok',
            'db' => $dbStatus,
        ]);
    }
}
