<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use StingBo\Mengine\Exceptions\InvalidParamException;

/**
 * 某个价位对应的单据链表.
 */
class LinkService
{
    public string $link;

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

    /**
     * 获取价位链上第一个单据.
     */
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

        return $this->current = $node;
    }

    /**
     * 设置起始指针.
     */
    public function setFirstPointer($node_name)
    {
        return $this->setNode('first', $node_name);
    }

    /**
     * 获取价位链上最后一个单据.
     */
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

        return $this->current = $node;
    }

    /**
     * 设置结束指针.
     */
    public function setLastPointer($node_name)
    {
        return $this->setNode('last', $node_name);
    }

    public function getCurrent($field = '')
    {
        if ($field) {
            $node = $this->getNode($field);
            if (!$node) {
                return false;
            }

            return $this->current = $node;
        }

        return $this->current ?? false;
    }

    /**
     * 获取当前单据前一个单据.
     */
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

        return $this->current = $node;
    }

    /**
     * 获取当前单据后一个单据.
     */
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

        return $this->current = $node;
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

    /**
     * 根据field获取某个节点.
     */
    public function getNode($field)
    {
        $node = Redis::hget($this->link, $field);

        if ($node) {
            return unserialize($node);
        }

        return null;
    }

    /**
     * 设置/更新某个field.
     */
    public function setNode($field, $order)
    {
        return Redis::hset($this->link, $field, serialize($order));
    }

    /**
     * 删除某个field.
     */
    public function deleteNode($order)
    {
        if ($order->is_first && $order->is_last) { // 只有一个节点则全删除
            Redis::hdel($this->link, 'first');
            Redis::hdel($this->link, 'last');
            Redis::hdel($this->link, $order->node);
        } elseif ($order->is_first) { // 首节点
            $next = $this->getNext();
            if (!$next) {
                throw new InvalidParamException(__METHOD__.' expects next node is not empty.');
            }
            Redis::hdel($this->link, $order->node);

            $next->is_first = true;
            $next->prev_node = null;
            $this->setFirstPointer($next->node);
            $this->setNode($next->node, $next);
        } elseif ($order->is_last) { // 尾结点
            $prev = $this->getPrev();
            if (!$prev) {
                throw new InvalidParamException(__METHOD__.' expects prev node is not empty.');
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
                throw new InvalidParamException(__METHOD__.' expects relation node is not empty.');
            }
            $prev->next_node = $next->node;
            $next->prev_node = $prev->node;

            Redis::hdel($this->link, $order->node);
            $this->setNode($next->node, $next);
            $this->setNode($prev->node, $prev);
        }
    }
}
