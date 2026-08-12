<?php

use App\Mcp\Servers\FoodOrderingServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/food-ordering', FoodOrderingServer::class);

Mcp::local('food-ordering', FoodOrderingServer::class);
