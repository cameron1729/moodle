<?php

require_once('config.php');

$mknode = fn($key) => navigation_node::create("Node $key", null, navigation_node::TYPE_CUSTOM, null, $key);
$children = fn($node) => array_map(fn($node) => $node->key, iterator_to_array($node->children));
$parents = fn(array $nodes) => array_map(fn($node) => $node->parent->key, $nodes);

$nav = $mknode("navigation");
$settingsnav = $mknode("settingsnavigation");
$secondarynav = $mknode("secondarynav");


$keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
$nodes = array_map($mknode, array_combine($keys, $keys));

// Initially some nodes are in primary nav, and some are in secondary:
$nav->add_node($nodes['A']);
$nav->add_node($nodes['E']);
$nodes['A']->add_node($nodes['B']);
$nodes['A']->add_node($nodes['C']);
$nodes['C']->add_node($nodes['F']);
$nodes['F']->add_node($nodes['G']);
$nodes['G']->add_node($nodes['H']);
$nodes['B']->add_node($nodes['D']);

function flatten($node, $depth = 0, $pre = ''): array {
    $children = iterator_to_array($node->children);
    $lastchild = $children[array_key_last($children)];
    $islast = fn($node) => $node->key === $lastchild->key;
    $merge = fn($node): array => [
        str_repeat(' ', $depth - mb_strlen($pre)) . $pre . ($islast($node) ? '└' : '├') . $node->key,
        ...$node->has_children() ? flatten($node, $depth+1, $pre . ($islast($node) ? ' ' : '│')) : []
    ];
    return array_reduce(
        $children,
        fn(array $nodes, $node): array => array_merge($nodes, $merge($node)),
        $depth === 0 ? [$node->key] : []
    );
}

function print_tree($node, $depth = 0, $pre = ''): string {
    $children = iterator_to_array($node->children);
    $lastchild = $children[array_key_last($children)];
    $merge = fn($node): string => str_repeat(' ', $depth - mb_strlen($pre)) . $pre . ($node->key !== $lastchild->key ? '├' : '└') . $node->key . "\n" .
                                  ($node->has_children() ? flatten($node, $depth+1, $node->has_siblings() ? $pre . '│' : '') : '');
    return array_reduce(
        $children,
        fn(string $nodes, $node): string => $nodes . $merge($node),
        $depth === 0 ? ($node->key . "\n") : ''
    );
}

echo '<pre>';
print_r(flatten($nav));
echo '</pre>';
