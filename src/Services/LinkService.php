<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use Illuminate\Support\Facades\Redis;

/**
 * 某个价位对应的单据链表.
 */
class LinkService
{
    public $link;

    public $current;

    public function __construct($link_name)
    {
        $this->link = $link_name;
    }

    public function getFirst()
    {
        $first = $this->getNode('first');
        if (!$first) {
            return false;
        }

        $node = $this->getNode($first);
        if (!$node) {
            return false;
        }

        return $this->current = json_decode($node);
    }

    public function getLast()
    {
        $last = $this->getNode('last');
        if (!$last) {
            return false;
        }

        $node = $this->getNode($last);
        if (!$node) {
            return false;
        }

        return $this->current = json_decode($node);
    }

    public function prev()
    {
        $field = $this->current->prev;
        if (!$field) {
            return false;
        }

        $node = $this->getNode($field);
        if (!$node) {
            return false;
        }

        return $this->current = json_decode($node);
    }

    public function next()
    {
        $field = $this->current->next;
        if (!$node_key) {
            return false;
        }

        $node = $this->getNode($field);
        if (!$node) {
            return false;
        }

        return $this->current = json_decode($node);
    }

    public function setNext($order)
    {
        $this->current->is_last = false;
        $this->current->next_node = $order->node;
        $order->prev_node = $last->node;
        Redis::hset($last->node_link, $last->node, json_encode($last));
    }

    public function getNode($field)
    {
        return Redis::hget($this->link, $field);
    }
}
