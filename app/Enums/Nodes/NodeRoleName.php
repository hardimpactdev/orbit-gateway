<?php

declare(strict_types=1);

namespace App\Enums\Nodes;

enum NodeRoleName: string
{
    case Gateway = 'gateway';
    case Vpn = 'vpn';
    case Router = 'router';
    case AppDevelopment = 'app-dev';
    case AppProduction = 'app-prod';
    case Database = 'database';
    case Agent = 'agent';
    case Ingress = 'ingress';
    case WebSocket = 'websocket';
    case S3 = 's3';
    case Metrics = 'metrics';
}
