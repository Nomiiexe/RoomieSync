<?php
declare(strict_types=1);

/**
 * RoomieSync - Reusable Pagination Component
 *
 * Provides server-side pagination calculations and standardized, responsive
 * UI controls across all modules (Chores, Bills, Expenses, Manage, Household, Reports).
 */

if (!function_exists('get_pagination_params')) {
    /**
     * Calculate pagination numbers and bounds.
     *
     * @param int $totalRecords Total count of records matching search/filters
     * @param int $defaultPerPage Default number of records per page
     * @param int[] $allowedPerPages Valid options for records per page
     * @param string $pageKey Query param name for page number
     * @param string $perPageKey Query param name for items per page
     * @return array<string, mixed>
     */
    function get_pagination_params(
        int $totalRecords,
        int $defaultPerPage = 10,
        array $allowedPerPages = [5, 10, 20, 50],
        string $pageKey = 'page',
        string $perPageKey = 'per_page'
    ): array {
        $totalRecords = max(0, $totalRecords);

        // Determine per-page
        $rawPerPage = $_GET[$perPageKey] ?? null;
        $perPage = is_string($rawPerPage) && ctype_digit($rawPerPage) ? (int) $rawPerPage : $defaultPerPage;
        if (!in_array($perPage, $allowedPerPages, true)) {
            $perPage = $defaultPerPage;
        }

        // Determine total pages
        $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $perPage) : 1;

        // Determine current page
        $rawPage = $_GET[$pageKey] ?? null;
        $page = is_string($rawPage) && ctype_digit($rawPage) ? (int) $rawPage : 1;
        if ($page < 1) {
            $page = 1;
        } elseif ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $from = $totalRecords > 0 ? $offset + 1 : 0;
        $to = $totalRecords > 0 ? min($offset + $perPage, $totalRecords) : 0;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'from' => $from,
            'to' => $to,
            'allowed_per_pages' => $allowedPerPages,
            'page_key' => $pageKey,
            'per_page_key' => $perPageKey,
        ];
    }
}

if (!function_exists('build_pagination_url')) {
    /**
     * Build URL for a specific page and per_page preserving other query parameters.
     *
     * @param int $targetPage
     * @param int $targetPerPage
     * @param string $pageKey
     * @param string $perPageKey
     * @param array<string, mixed> $extraParams
     * @param string $anchor
     * @return string
     */
    function build_pagination_url(
        int $targetPage,
        int $targetPerPage,
        string $pageKey,
        string $perPageKey,
        array $extraParams = [],
        string $anchor = ''
    ): string {
        $params = $_GET;
        $params[$pageKey] = (string) $targetPage;
        $params[$perPageKey] = (string) $targetPerPage;

        foreach ($extraParams as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = (string) $v;
            }
        }

        $query = http_build_query($params);
        $url = '?' . $query;
        if ($anchor !== '') {
            $url .= '#' . ltrim($anchor, '#');
        }
        return $url;
    }
}

if (!function_exists('render_pagination')) {
    /**
     * Render accessible, responsive RoomieSync pagination controls.
     *
     * @param array<string, mixed> $pagination Output of get_pagination_params()
     * @param array<string, mixed> $extraParams Additional or overridden query parameters
     * @param string $anchor Optional anchor hash for smooth section targeting
     * @return string HTML markup
     */
    function render_pagination(
        array $pagination,
        array $extraParams = [],
        string $anchor = ''
    ): string {
        $page = (int) $pagination['page'];
        $perPage = (int) $pagination['per_page'];
        $totalRecords = (int) $pagination['total_records'];
        $totalPages = (int) $pagination['total_pages'];
        $from = (int) $pagination['from'];
        $to = (int) $pagination['to'];
        $pageKey = (string) $pagination['page_key'];
        $perPageKey = (string) $pagination['per_page_key'];
        $allowed = (array) $pagination['allowed_per_pages'];

        if ($totalRecords === 0) {
            return '';
        }

        $html = '<div class="rs-pagination-container my-3" data-pagination-anchor="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">';

        // 1. Record range indicator
        $html .= '<div class="rs-pagination-info small text-secondary-custom text-center text-md-start">';
        $html .= 'Showing <strong class="text-main">' . $from . '</strong> to <strong class="text-main">' . $to . '</strong> of <strong class="text-main">' . $totalRecords . '</strong> records';
        $html .= '</div>';

        // 2. Right side controls: Page size selector + Page navigation
        $html .= '<div class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-3">';

        // Per-page selector
        $html .= '<div class="d-flex align-items-center gap-2 rs-page-size-wrapper">';
        $html .= '<label class="small text-secondary-custom text-nowrap mb-0" for="rsPageSizeSelect_' . htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8') . '">Show:</label>';
        $html .= '<select class="form-select form-select-sm rs-page-size-select" id="rsPageSizeSelect_' . htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'data-page-key="' . htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'data-per-page-key="' . htmlspecialchars($perPageKey, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'data-anchor="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'onchange="rsHandlePageSizeChange(this)">';
        foreach ($allowed as $opt) {
            $selected = ((int) $opt === $perPage) ? ' selected' : '';
            $html .= '<option value="' . (int) $opt . '"' . $selected . '>' . (int) $opt . ' / page</option>';
        }
        $html .= '</select>';
        $html .= '</div>';

        // Page navigation buttons: Prev and Next only
        $html .= '<nav aria-label="Page navigation" class="rs-pagination-nav">';
        $html .= '<ul class="pagination pagination-sm mb-0 rs-pagination-list">';

        // Previous button
        $isFirst = ($page <= 1);
        $prevUrl = $isFirst ? '#' : build_pagination_url($page - 1, $perPage, $pageKey, $perPageKey, $extraParams, $anchor);
        $html .= '<li class="page-item' . ($isFirst ? ' disabled' : '') . '">';
        $html .= '<a class="page-link rs-page-link rs-page-prev" href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '" ' . ($isFirst ? 'tabindex="-1" aria-disabled="true"' : '') . ' aria-label="Previous">';
        $html .= '<i class="bi bi-chevron-left me-1"></i> Prev';
        $html .= '</a></li>';

        // Next button
        $isLast = ($page >= $totalPages);
        $nextUrl = $isLast ? '#' : build_pagination_url($page + 1, $perPage, $pageKey, $perPageKey, $extraParams, $anchor);
        $html .= '<li class="page-item' . ($isLast ? ' disabled' : '') . '">';
        $html .= '<a class="page-link rs-page-link rs-page-next" href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '" ' . ($isLast ? 'tabindex="-1" aria-disabled="true"' : '') . ' aria-label="Next">';
        $html .= 'Next <i class="bi bi-chevron-right ms-1"></i>';
        $html .= '</a></li>';

        $html .= '</ul>';
        $html .= '</nav>';

        $html .= '</div>'; // End right side
        $html .= '</div>'; // End d-flex
        $html .= '</div>'; // End container

        return $html;
    }
}
