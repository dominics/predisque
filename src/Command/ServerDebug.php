<?php

namespace Predisque\Command;

class ServerDebug extends Command
{
    public function getId()
    {
        return 'DEBUG';
    }

    public function parseResponse($data)
    {
        if (is_array($data)) {
            $result = array();

            for ($i = 0; $i < count($data); ++$i) {
                $result[$data[$i]] = $data[++$i];
            }

            return $result;
        }

        return $data;
    }
}
