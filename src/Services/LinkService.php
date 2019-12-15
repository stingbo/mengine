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

    public function init($order)
    {
        $this->current->is_last = false;
        $this->current->next_node = $order->node;
        $order->prev_node = $last->node;
        Redis::hset($last->node_link, $last->node, json_encode($last));
    }

    public function setLast($order)
    {
        $this->getLast();
        $this->current->is_last = false;
        $this->current->next_node = $order->node;
        $this->setNode($this->current->node, $this->current);

        $order->prev_node = $this->current->node;
        $this->setNode('last', $order->node);

        $order->is_last = true;
        $this->setNode($order->node, $order);
    }

    public function getNode($field)
    {
        return Redis::hget($this->link, $field);
    }

    public function setNode($field, $order)
    {
        return Redis::hset($this->link, $field, json_encode($order));
    }
}
