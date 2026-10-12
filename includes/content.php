<?php

function blog_content_html(string $content): string
{
    $content = trim($content);
    if ($content === '') {
        return '';
    }

    // Plain-text posts need explicit paragraphs: browsers collapse newlines.
    if (!preg_match('/<\/?[a-z][^>]*>/i', $content)) {
        $paragraphs = preg_split('/\n\s*\n/', str_replace(["\r\n", "\r"], "\n", $content));
        return implode("\n", array_map(static function (string $paragraph): string {
            return '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        }, $paragraphs));
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $allowed = ['p', 'br', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img', 'hr', 'sub', 'sup', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
    $clean = static function (DOMNode $node) use (&$clean, $allowed): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form'], true)) {
                $node->removeChild($child);
                continue;
            }
            $clean($child);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                $keep = in_array($name, ['title', 'alt'], true);
                if (($tag === 'a' && $name === 'href') || ($tag === 'img' && $name === 'src')) {
                    $url = preg_replace('/[\x00-\x20\x7f]/', '', $value);
                    $keep = $url !== '' && (preg_match('~^https?://~i', $url) || ($tag === 'a' && preg_match('~^(mailto:|tel:|#)~i', $url)) || !preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $url));
                }
                if ($name === 'class') {
                    $classes = array_filter(preg_split('/\s+/', $value), static fn(string $class): bool => (bool) preg_match('/^ql-(align-(center|right|justify)|indent-[1-8])$/', $class));
                    $child->setAttribute('class', implode(' ', $classes));
                    $keep = count($classes) > 0;
                }
                if (!$keep) {
                    $child->removeAttribute($name);
                }
            }
        }
    };
    $clean($document);
    $html = '';
    foreach ($document->documentElement->childNodes as $node) {
        $html .= $document->saveHTML($node);
    }
    return $html;
}

function blog_content_is_empty(string $content): bool
{
    $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(str_replace("\xc2\xa0", ' ', $text)) === '' && !preg_match('/<img\b[^>]*\bsrc=/i', $content);
}
