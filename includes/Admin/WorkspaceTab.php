<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Admin;

use CodeOn\Framework\Admin\Tab;

final class WorkspaceTab extends Tab
{
    public function __construct(
        private readonly string $view,
        private readonly string $title,
    ) {
    }

    public function slug(): string
    {
        return $this->view;
    }

    public function label(): string
    {
        return $this->title;
    }

    public function render(string $nonceAction): void
    {
        printf(
            '<div class="csr-workspace" data-csr-view="%s"><p role="status">%s</p></div>',
            esc_attr($this->view),
            esc_html__('Loading shipping rules…', 'codeon-shipping-rules')
        );
        echo '<noscript><p class="description">'
            . esc_html__('Enable JavaScript to edit shipping rules, preview rates, and import settings.', 'codeon-shipping-rules')
            . '</p></noscript>';
    }
}
