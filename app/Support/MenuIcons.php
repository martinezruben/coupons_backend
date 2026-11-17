<?php

namespace App\Support;

/** Iconos (Heroicons contorno) que se pueden elegir para el menú. */
final class MenuIcons
{
    public const ALL = [
        'home', 'user-group', 'users', 'user-circle', 'gift', 'ticket', 'qr-code', 'banknotes', 'credit-card', 'receipt-percent', 'shopping-cart', 'shopping-bag',
        'chart-bar', 'chart-pie', 'presentation-chart-line', 'clipboard-document-list', 'document-text', 'document-chart-bar', 'folder', 'archive-box',
        'building-office-2', 'building-storefront', 'map-pin', 'map', 'globe-alt', 'device-phone-mobile', 'computer-desktop', 'server', 'cpu-chip', 'key',
        'wrench-screwdriver', 'cog-6-tooth', 'adjustments-horizontal', 'shield-check', 'lock-closed', 'finger-print', 'bell', 'envelope', 'chat-bubble-left-right',
        'bolt', 'star', 'heart', 'flag', 'tag', 'bookmark', 'calendar', 'clock', 'truck', 'cube', 'puzzle-piece', 'academic-cap', 'briefcase', 'calculator',
        'magnifying-glass', 'arrow-path', 'arrows-right-left', 'link', 'list-bullet', 'queue-list', 'squares-2x2', 'rectangle-stack', 'rectangle-group', 'table-cells',
        'identification', 'inbox', 'megaphone', 'light-bulb', 'sparkles', 'rocket-launch', 'trophy', 'scale', 'wallet', 'currency-dollar', 'circle-stack', 'command-line',
    ];

    public static function valid(?string $name): bool
    {
        return $name !== null && in_array($name, self::ALL, true);
    }

    /**
     * @param  list<string>  $extra  iconos originales de las pantallas, para que también se vean en el editor
     * @return array<string, string> nombre => SVG, para el selector del editor
     */
    public static function svgMap(array $extra = []): array
    {
        $out = [];
        foreach (array_values(array_unique(array_merge(self::ALL, array_filter($extra)))) as $name) {
            try {
                $out[$name] = svg('heroicon-o-'.$name, 'h-5 w-5')->toHtml();
            } catch (\Throwable) {
                // icono ausente en esta versión del paquete: no se ofrece
            }
        }

        return $out;
    }
}
