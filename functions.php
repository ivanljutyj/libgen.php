<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

use Kunnu\Dropbox\DropboxApp;
use Kunnu\Dropbox\DropboxFile;
use Kunnu\Dropbox\Dropbox;

const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36';

function request(string $url, array $params = []) {
    $handlerStack = HandlerStack::create(new CurlHandler());
    $handlerStack->push(Middleware::retry(retryDecider(), retryDelay()));
    $client = new Client(['handler' => $handlerStack]);
    $params['headers'] = ['User-Agent' => USER_AGENT];
    $params['on_stats'] = function(TransferStats $stats) use (&$redirectURL) {
        $redirectURL = $stats->getEffectiveUri();
    };

    $response = $client->get($url, $params);

    return ['html' => $response->getBody()->getContents(), 'url' => $redirectURL->getHost()];
}

function search(string $text) {
    return request(BASE_URL . '/search.php?' . encodeURLQuery($text));
}

function encodeURLQuery(string $search) {
    return http_build_query([
        'req' => $search,
        'lg_topic' => 'libgen',
        'open' => 0,
        'view' => 'simple',
        'res' => 100,
        'phrase' => 1,
        'column' => 'def'
    ]);
}

function parseBooks(string $title, string $format = 'pdf') {
    if (isset($_GET['format'])) $format = $_GET['format'];
    $results = search($title);
    $dom = new \DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($results['html']);
    $xpath = new \DOMXPath($dom);
    $table = $xpath->query('//table[@class="c"]/tr');
    $books = [];
    foreach (array_slice(iterator_to_array($table), 1) as $key => $row) {
        $td = $row->getElementsByTagName('td');
        $bookFormat = $td->item(8)->textContent;
        $bookLanguage = $td->item(6)->textContent;

        if ($bookFormat === $format && $bookLanguage === 'English') {
            $books[$key] = [
                'author' => $td->item(1)->textContent,
                'title' => $td->item(2)->textContent,
                'url' => formatDownloadLink($td->item(2)->getElementsByTagName('a')->item(0)->getAttribute('href')),
                'publisher' => $td->item(3)->textContent,
                'year' => $td->item(4)->textContent,
                'pages' => (int)$td->item(5)->textContent,
                'language' => $bookLanguage,
                'size' => $td->item(7)->textContent,
                'format' => $bookFormat
            ];
        }
    }

    return array_values($books);
}

function formatDownloadLink(string $url)
{
    parse_str($url, $result);
    $md5 = array_values($result)[0];
    if (!isValidMd5($md5)) {
        return 'N/A';
    }
    $protocol = strtolower(substr($_SERVER["SERVER_PROTOCOL"],0,strpos( $_SERVER["SERVER_PROTOCOL"],'/'))).'://';

    return $protocol . $_SERVER['HTTP_HOST'] . '/books/download/' . $md5;
}

function isValidMd5(string $md5)
{
    return preg_match('/^[a-f0-9A-F]{32}$/', $md5);
}

function getDownloadPage(string $url) {
    $response = request($url);
    $dom = new \DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($response['html']);
    $xpath = new \DOMXPath($dom);
    $url = $xpath->query('//a[contains(@title, "Gen.lib.rus.ec")]');

    return $url->item(0)->getAttribute('href');
}

function getDownloadUrl(string $url) {
    $page = getDownloadPage($url);
    $response = request($page);
    $dom = new \DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($response['html']);
    $xpath = new \DOMXPath($dom);
    $url = $xpath->query('//*[@id="info"]/h2/a');

    return 'http://' . $response['url'] . $url->item(0)->getAttribute('href');
}

function downloadBook(string $url) {
    $name = urldecode(basename($url));
    $path = fopen($name,'w');
    request($url, ['save_to' => $path]);
    uploadToDropBox($name);
    unlink($name);
}

function uploadToDropBox(string $file)
{
    $app = new DropboxApp(
        getenv('DROPBOX_APP_KEY'),
        getenv('DROPBOX_APP_SECRET'),
        getenv('DROPBOX_ACCESS_TOKEN')
    );

    $dropbox = new Dropbox($app);
    $uploadedFile = $dropbox->upload($file, '/'. $file, ['autorename' => true]);

    echo $uploadedFile->getPathDisplay();
}

function getRemoteFileSize($url)
{
    $client = new Client();
    $response = $client->head($url);

    return $response->getHeader('Content-Length')[0];
}

function retryDecider()
{
    return function (
        $retries,
        Request $request,
        Response $response = null,
        RequestException $exception = null
    ) {
        // Limit the number of retries to 5
        if ($retries >= 5) {
            return false;
        }

        // Retry connection exceptions
        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($response) {
            // Retry on server errors
            if ($response->getStatusCode() >= 500 ) {
                return true;
            }
        }

        return false;
    };
}

function retryDelay()
{
    return function ($numberOfRetries) {
        return 1000 * $numberOfRetries;
    };
}

function addToQueue(string $url)
{
    $rabbitmq = parse_url(getenv('CLOUDAMQP_URL'));
    $connection = new AMQPStreamConnection(
        $rabbitmq['host'],
        5672,
        $rabbitmq['user'],
        $rabbitmq['pass'],
        substr($rabbitmq['path'], 1) ?: '/'
    );

    $channel = $connection->channel();
    $channel->queue_declare('books', false, false, false, false);
    $msg = new AMQPMessage($url);
    $channel->basic_publish($msg, '', 'books');
    echo " [x] Sent '".urldecode(basename($url))."'";
    $channel->close();
    $connection->close();
};
