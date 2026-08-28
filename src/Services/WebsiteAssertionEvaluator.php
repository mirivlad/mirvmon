<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\WebsiteEndpointDefinition;
use DOMDocument;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class WebsiteAssertionEvaluator
{
    /**
     * @return array{
     *   passed: bool,
     *   status_passed: ?bool,
     *   content_results: list<array{kind: string, passed: bool, safe_message: string}>
     * }
     */
    public function evaluate(int $statusCode, string $body, WebsiteEndpointDefinition $definition): array
    {
        $statusPassed = $definition->statusCheckEnabled
            ? $definition->expectedStatuses->accepts($statusCode)
            : null;
        $contentResults = [];
        $xpath = null;

        foreach ($definition->contentChecks as $check) {
            if ($check['kind'] === 'page_text') {
                $passed = str_contains($body, $check['needle']);
                $contentResults[] = [
                    'kind' => 'page_text',
                    'passed' => $passed,
                    'safe_message' => $passed ? 'Page text found.' : 'Page text not found.',
                ];
                continue;
            }

            $xpath ??= $this->xpath($body);
            $passed = false;
            if ($xpath !== null && $check['selector'] !== null) {
                try {
                    $query = (new CssSelectorConverter())->toXPath($check['selector']);
                    $nodes = $xpath->query($query);
                    if ($nodes !== false) {
                        foreach ($nodes as $node) {
                            if ($check['needle'] === '' || str_contains($node->textContent, $check['needle'])) {
                                $passed = true;
                                break;
                            }
                        }
                    }
                } catch (Throwable) {
                    $passed = false;
                }
            }
            $contentResults[] = [
                'kind' => 'css',
                'passed' => $passed,
                'safe_message' => $passed ? 'Selector matched.' : 'Selector did not match.',
            ];
        }

        $contentPassed = !in_array(false, array_column($contentResults, 'passed'), true);

        return [
            'passed' => $statusPassed !== false && $contentPassed,
            'status_passed' => $statusPassed,
            'content_results' => $contentResults,
        ];
    }

    private function xpath(string $body): ?DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadHTML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
