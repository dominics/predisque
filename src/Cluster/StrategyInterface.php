<?php

namespace Predisque\Cluster;

use Predis\Connection\NodeConnectionInterface;

interface StrategyInterface
{
    /**
     * @param array $nodes
     * @return NodeConnectionInterface
     */
    public function pickNode(array $nodes): NodeConnectionInterface;
}
