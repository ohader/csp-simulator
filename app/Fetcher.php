<?php
declare(strict_types=1);
namespace App;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler;
use TS\Web\UrlFinder\CssUrlFinder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * @author Oliver Hader <oliver.hader@typo3.org>
 * @licence MIT
 */
class Fetcher
{
    private const FORWARD_REQUEST_HEADER_NAMES = [
        'referer',
        'pragema',
        'content-type',
        'cache-control',
        'accept-language',
        'accept-encoding',
        'accept',
        'user-agent',
    ];
    private const FORWARD_RESPONSE_HEADER_NAMES = [
        'vary',
        'last-modified',
        'content-type',
        'content-length',
        'x-content-type-options',
    ];

    public readonly UriInterface $baseUri;
    public readonly ?ResponseInterface $response;
    public readonly string $errorMessage;

    public function __construct(public readonly UriInterface $uri, public readonly Request $request)
    {
        $this->baseUri = $uri->withPath('/')->withQuery('');
        try {
            $client = new Client();
            // TLS verification is disabled for calling (other) local DDEV sites
            $headers = array_map(
                static fn (array $headers) => implode('; ', $headers),
                $this->request->headers->all()
            );
            $headers = array_filter(
                $headers,
                static fn (string $headerName) => in_array(
                    $headerName,
                    self::FORWARD_REQUEST_HEADER_NAMES,
                    true
                ) || str_starts_with($headerName, 'sec-'),
                ARRAY_FILTER_USE_KEY
            );
            $this->response = $client->get($uri, [
                'verify' => false,
                'allow_redirects' => true,
                'headers' => $headers,
            ]);
            $this->errorMessage = '';
        } catch (\Throwable $t) {
            $this->errorMessage = $t->getMessage();
            $this->response = null;
        }
    }

    public function getContentSecurityPolicy(): string
    {
        $csp = $this->response?->getHeaderLine('Content-Security-Policy') ?? '';
        $csp = preg_replace(
            '#(report-uri|frame-ancestors)\h+[^;]+(;|$)#i',
            '',
            $csp
        );
        return $csp;
    }

    public function getAppliedResponse(string $customCsp = ''): null|Response|ResponseInterface
    {
        if ($this->response === null) {
            return null;
        }
        $body = (string)$this->response->getBody();
        $body = $this->adjustRawData($body, $this->response->getHeaderLine('Content-Type'));
        $originCsp = $this->response?->getHeaderLine('Content-Security-Policy') ?? '';
        $headers = $this->adjustHeaders($this->response);

        $nonce = '';
        if (preg_match("#'nonce-([^']+)'#", $originCsp, $matches)) {
            $nonce = $matches[1];
        }

        $customCsp = preg_replace(
            "#'nonce-[^']+'#",
            sprintf("'nonce-%s'", $nonce ?? 'St@t1cN0nc3'),
            $customCsp
        );
        $headers['content-security-policy'] = $customCsp;
        $headers['x-fetched-uri'] = (string)$this->uri;

        return response($body, $this->response->getStatusCode(), $headers);
    }

    public function getProxiedResponse(): null|Response|ResponseInterface
    {
        if ($this->response === null) {
            return null;
        }
        $data = (string)$this->response->getBody();
        $data = $this->adjustRawData($data, $this->response->getHeaderLine('Content-Type'));
        $headers = $this->adjustHeaders($this->response);
        $headers['x-fetched-uri'] = (string)$this->uri;

        return response($data, $this->response->getStatusCode(), $headers);
    }

    private function adjustRawData(string $content, string $contentType): string
    {
        $contentTypeParts = explode(';', $contentType);
        $contentTypeParts = array_map(trim(...), $contentTypeParts);
        if ($contentTypeParts[0] === 'text/html') {
            return $this->adjustHtmlData($content);
        }
        if ($contentTypeParts[0] === 'text/css') {
            return $this->adjustCss($content);
        }
        /*
        if (in_array($contentTypeParts[0], ['text/javascript', 'application/javascript'], true)) {
            return $content;
        }
        */
        return $content;
    }

    private function adjustHtmlData(string $content): string
    {
        $changed = false;
        $baseUri = rtrim((string)$this->baseUri, '/');
        $xpath = sprintf(
            '//*/@href | //*/@src | //*/@*[starts-with(., "/")] | //*/@*[starts-with(., "%s")]',
            addcslashes($baseUri, '"\\')
        );
        $crawler = new Crawler($content);
        $crawler
            ->filterXPath($xpath)
            ->each(function (Crawler $item) use ($baseUri, &$changed) {
                $node = $item->getNode(0);
                if (!$node instanceof \DOMAttr) {
                    return;
                }
                $value = trim($node->value);
                if ($this->shallSkipUrl($value)) {
                    return;
                }
                if (!str_starts_with($value, $baseUri)) {
                    $value = $baseUri . $value;
                }
                $node->nodeValue = $this->generateProxyRouteUrl($value);
                $changed = true;
            });
        $crawler
            ->filterXPath('//style[contains(text(), "url")] | //*/@*[contains(., "url")]')
            ->each(function (Crawler $item) use (&$changed) {
                $node = $item->getNode(0);
                if ($node instanceof \DOMElement && !$this->shallSkipUrl($node->nodeValue)) {
                    $node->nodeValue = $this->adjustCss($node->nodeValue);
                    $changed = true;
                }
                if ($node instanceof \DOMAttr && !$this->shallSkipUrl($node->value)) {
                    $node->value = $this->adjustCss($node->value);
                    $changed = true;
                }
            });
        $crawler
            ->filterXPath('//script/text()[string-length(.) > 0]')
            ->each(function (Crawler $item) use (&$changed) {
                $node = $item->getNode(0);
                if (!$node instanceof \DOMNode) {
                    return;
                }
                $baseUri = rtrim((string)$this->baseUri, '/');
                $node->nodeValue = preg_replace_callback(
                    '#(?P<guard>["\'`])(?P<value>[^\1]+?)\1#', // ungreedy (/U or +?)
                    function (array $matches) use ($baseUri, &$changed) {
                        $value = $matches['value'];
                        if ($this->shallSkipUrl($value)) {
                            return $matches[0];
                        }
                        if (str_starts_with($value, $baseUri)) {
                            $absoluteUrl = $value;
                        } elseif (str_starts_with($value, '/')) {
                            $absoluteUrl = $baseUri . $value;
                        } else {
                            return $matches[0];
                        }
                        $changed = true;
                        return $matches['guard']
                            . $this->generateProxyRouteUrl($absoluteUrl)
                            . $matches['guard'];
                    },
                    $node->nodeValue
                );
            });
        return $changed ? $crawler->html() : $content;
    }

    private function adjustCss(string $content): string
    {
        $changed = false;
        $finder = new CssUrlFinder();
        $host = $this->uri->getHost();
        $finder->setDocument($content, (string)$this->uri);
        foreach ($finder->find('*') as $url) {
            if ($url->isEmpty()) {
                continue;
            }
            // having URL like `#some-fragment`
            if ($url->host->isEmpty() && $url->path->isEmpty() && !$url->fragment->isEmpty())  {
                continue;
            }
            if (str_starts_with($url->getUrl(), '../')) {
                $resolvedPath = PathUtility::getAbsolutePathOfRelativeReferencedFileOrPath(
                    (string)$this->uri->withQuery(''),
                    $url->getUrl()
                );
                $absoluteUrl = $resolvedPath;
            } else {
                $absoluteUrl = $this->baseUri . $url->getUrl();
            }
            if ($url->host->equals($host)) {
                $changed = true;
                $url->replace($this->generateProxyRouteUrl($absoluteUrl));
            } elseif ($url->isRelative()) {
                $changed = true;
                $url->replace($this->generateProxyRouteUrl($absoluteUrl));
            }
        }
        return $changed ? $finder->getDocument() : $content;
    }

    private function adjustHeaders(ResponseInterface $response): array
    {
        $headers = $this->response->getHeaders();
        $headerValues = array_map(
            static fn (array $headers) => implode('; ', $headers),
            array_values($headers)
        );
        $headerNames = array_map(strtolower(...), array_keys($headers));
        $headers = array_combine($headerNames, $headerValues);
        $headers = array_filter(
            $headers,
            static fn (string $headerName) => in_array(
                $headerName,
                self::FORWARD_RESPONSE_HEADER_NAMES,
                true
            ),
            ARRAY_FILTER_USE_KEY
        );
        return $headers;
    }

    private function generateProxyRouteUrl(string $url): string
    {
        preg_match('/^(?P<uri>[^#\h]+)(?P<tail>(?:#|\h+).*)?$/', $url, $matches);
        return route('proxy', [
            'value' => StringUtility::base64urlEncode($matches['uri'])
            ]) . ($matches['tail'] ?? '');
    }

    private function shallSkipUrl(string $url): bool
    {
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException $e) {
            if (in_array($e->getCode(), [1436717338], true)) {
                return true;
            }
            throw $e;
        };
        return
            ($uri->getHost() !== '' && $uri->getHost() !== $this->uri->getHost())
            || ($uri->getScheme() !== '' && !in_array($uri->getScheme(), ['http', 'https'], true));
    }
}
