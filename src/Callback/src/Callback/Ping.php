<?php

namespace rollun\callback\Callback;

use Zend\Diactoros\Response\JsonResponse;

/**
 * Class Ping
 *
 * @author    r.ratsun <r.ratsun.rollun@gmail.com>
 *
 * @copyright Copyright © 2014 Rollun LC (http://rollun.com/)
 * @license   LICENSE.md New BSD License
 */
class Ping
{
    /**
     * @return array
     */
    public function __invoke($value)
    {
        $test = '123';

        return [
            'ok' => true
        ];
    }
}
