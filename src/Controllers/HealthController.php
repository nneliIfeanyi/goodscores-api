<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Config\Database;
use PDOException;

class HealthController
{
    public function check(): void
    {
        $dbOk = false;
        $dbError = null;
        try {
            $db = Database::getInstance();
            $db->query('SELECT 1');
            $dbOk = true;
        } catch (PDOException $e) {
            $dbError = $e->getMessage();
        }

        Response::json([
            'status'  => $dbOk ? 'ok' : 'degraded',
            'app'     => $_ENV['APP_NAME'] ?? 'GoodScores',
            'time'    => date('c'),
            'database'=> $dbOk ? 'connected' : 'error',
            'db_error'=> $dbError,
        ], $dbOk ? 200 : 503);
    }
}
