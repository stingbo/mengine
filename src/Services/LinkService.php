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

    /**
     * 第一个价位单初始化节点.
     */
    public function init($order)
    {
        $order->is_first = true;
        $order->is_last = true;

        $this->setFirstPointer($order->node);
        $this->setLastPointer($order->node);
        $this->setNode($order->node, $order);
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

    /**
     * 设置起始指针.
     */
    public function setFirstPointer($node_name)
    {
        return Redis::hset($this->link, 'first', $node_name);
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

    /**
     * 设置结束指针.
     */
    public function setLastPointer($node_name)
    {
        return Redis::hset($this->link, 'last', $node_name);
    }

    public function getCurrent($field = '')
    {
        if ($field) {
            $node = $this->getNode($field);
            if (!$node) {
                return false;
            }

            return $this->current = json_decode($node);
        }

        return $this->current ?? false;
    }

    public function getPrev()
    {
        $field = $this->current->prev_node;
        if (!$field) {
            return false;
        }

        $node = $this->getNode($field);
        if (!$node) {
            return false;
        }

        return $this->current = json_decode($node);
    }

    public function getNext()
    {
        $field = $this->current->next_node;
        if (!$field) {
            return false;
        }

        $node = $this->getNode($field);
        if (!$node) {
            return false;
        }

        return $this->current = json_decode($node);
    }

    public function setLast($order)
    {
        $this->getLast();
        $this->current->is_last = false;
        $this->current->next_node = $order->node;
        $this->setNode($this->current->node, $this->current);

        $order->prev_node = $this->current->node;
        $this->setLastPointer($order->node);

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

    public function deleteNode($order)
    {
        if ($order->is_first && $order->is_last) { // 只有一个节点则全删除
            Redis::hdel($this->link, 'first');
            Redis::hdel($this->link, 'last');
            Redis::hdel($this->link, $order->node);
        } elseif ($order->is_first) { // 首节点
            $next = $this->getNext();
            if (!$next) {
                throw new InvalidArgumentException(__METHOD__.' expects next node is not empty.');
            }
            Redis::hdel($this->link, $order->node);

            $next->is_first = true;
            $next->prev_node = null;
            $this->setFirstPointer($next->node);
            $this->setNode($next->node, $next);
        } elseif ($order->is_last) { // 尾结点
            $prev = $this->getPrev();
            if (!$prev) {
                throw new InvalidArgumentException(__METHOD__.' expects prev node is not empty.');
            }
            Redis::hdel($this->link, $order->node);

            $prev->is_last = true;
            $prev->next_node = null;
            $this->setLastPointer($prev->node);
            $this->setNode($prev->node, $prev);
        } else { // 中间结点
            $prev = $this->getNode($order->prev_node);
            $next = $this->getNode($order->next_node);
            if (!$prev || !$next) {
                throw new InvalidArgumentException(__METHOD__.' expects relation node is not empty.');
            }
            $prev = json_decode($prev);
            $next = json_decode($next);
            $prev->next_node = $next->node;
            $next->prev_node = $prev->node;

            Redis::hdel($this->link, $order->node);
            $this->setNode($next->node, $next);
            $this->setNode($prev->node, $prev);
        }
    }
}
