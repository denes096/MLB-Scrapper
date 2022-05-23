<?php
declare(strict_types = 1);

require_once (__DIR__ . '/helpers.php');

$baseUrl = "https://www.baseball-reference.com";
$todayGamesQuery = "/previews/index.shtml";
$todayGamesUrl = $baseUrl . $todayGamesQuery;

logMessage("Get today's games");

/**
 * Contains the data about today games
 * 
 * [
 *      0 => [
 *          'homeTeam' => [
 *              'teamName' => '...',
 *              'teamStats' => [...],
 *              'pitcherAdvancedStats' => [...]
 *          ],
 *          'awayTeam' => [
 *              'teamName' => '...',
 *              'teamStats' => [...],
 *              'pitcherAdvancedStats'  => [...]
 *          ]
 *      ],
 *      1 => [
 *          'homeTeam' => [
 *              'teamName' => '...',
 *              'teamStats' => [...],
 *              'pitcherAdvancedStats' => [...]
 *          ],
 *          'awayTeam' => [
 *              'teamName' => '...',
 *              'teamStats' => [...],
 *              'pitcherAdvancedStats'  => [...]
 *          ]
 *      ],
 *      ...
 * ]
 * 
 */
$games = [];

$todayGamesContent = getContentFrom($todayGamesUrl);
if ($todayGamesContent) {
    logMessage("Page content downloaded");


    // modify state
    $libxmlPreviousState = libxml_use_internal_errors(true);
    // parse
    $dom = new DOMDocument();
    $dom->loadHTML($todayGamesContent);
    // handle errors
    libxml_clear_errors();
    // restore
    libxml_use_internal_errors($libxmlPreviousState);
    
    // path parse
    $todayGamesXpath = new DomXPath($dom);
    // get the today games path
    $todayGames = $todayGamesXpath->query('//div[@id="content"]//div[contains(@class, "game_summary")]');

    $i = 0;
    // Iterate trough games
    /** @var DOMElement $todayGame */
    foreach($todayGames as $todayGame) {
        $gameEntity = [
            'homeTeam' => [
                'teamName' => null,
                'teamStats' => [],
                'pitcherAdvancedStats' => []
            ],
            'awayTeam' => [
                'teamName' => null,
                'teamStats' => [],
                'pitcherAdvancedStats' => []
            ],
        ];

        $i++;
        logMessage(" - get team stats");

        // check links
        $gameUrls = $todayGamesXpath->query('.//a', $todayGame);
        /** @var DOMElement $gameUrl */
        foreach($gameUrls as $gameUrl) {
            $href = $gameUrl->getAttribute('href');
            // check anchor tag href points to teams page
            if (str_starts_with($href, '/teams')) {
                // fill away team name
                if (!$gameEntity['awayTeam']['teamName']) {
                    $gameEntity['awayTeam']['teamName'] = $gameUrl->nodeValue;
                    $gameEntity['awayTeam']['teamStats'] = getTeamStats($href, $gameUrl->nodeValue, false);
                } else {
                    $gameEntity['homeTeam']['teamName'] = $gameUrl->nodeValue;
                    $gameEntity['homeTeam']['teamStats'] = getTeamStats($href, $gameUrl->nodeValue, true);
                }
            }
        }

        logMessage(" - get pitcher stats");

        // get pitchers
        /** @var DOMElement $gameUrl */
        foreach($gameUrls as $gameUrl) {
            
            $href = $gameUrl->getAttribute('href');
            // pitcher url
            if (str_contains($href, '/players')) {
                if (empty($gameEntity['awayTeam']['pitcherAdvancedStats'])) {
                    $gameEntity['awayTeam']['pitcherAdvancedStats'] = getPitcherAdvancedStats($gameUrl->getAttribute('href'), $gameUrl->nodeValue, false);
                } else {
                    $gameEntity['homeTeam']['pitcherAdvancedStats'] = getPitcherAdvancedStats($gameUrl->getAttribute('href'), $gameUrl->nodeValue, true);
                }
            }
        }

        logMessage("#{$i} Game data (" . $gameEntity['awayTeam']['teamName'] . " @ " . $gameEntity['homeTeam']['teamName'] . ") collected");

        $games[] = $gameEntity;
    }

    logMessage("----- PROCESS GAMES DATA ------");

    $result = [];

    foreach ($games as $game) {
        $result[$game['homeTeam']['teamName']] = [...$game['homeTeam']['teamStats'], ...$game['awayTeam']['pitcherAdvancedStats'], 1];
        $result[$game['awayTeam']['teamName']] = [...$game['awayTeam']['teamStats'], ...$game['homeTeam']['pitcherAdvancedStats'], 0];
    }

    $json = json_encode($result);

    $fileName = "MLB-" . date('M-d-Y') . ".json";
    //write json to file
    if (file_put_contents($fileName, $json)) {
        logMessage('Result saved to ' . $fileName);
    } else {
        logMessage('Could not save file');
    }

} else {
    logMessage('Failed to get content. Exit...');
    die();
}

function getTeamStats(string $url, string $name, bool $isHome): array
{
    $nicks = [
        'TBR' => 'TBD', //tempa bay rays
        'MIA' => 'FLA', // Miami Marlins
        'LAA' => 'ANA', // Los Angeles Angels 
    ];

    $stats = [];

    logMessage("   " . ($isHome ? "home" : "away") . " Team is " . $name);
    
    $nick1 = explode('/', $url)[2];
    $nick = $nicks[$nick1] ?? $nick1;

    $teamPage = getContentFrom(sprintf("https://www.baseball-reference.com/teams/%s/batteam.shtml#yby_team_bat", $nick));

    preg_match_all('/<a\shref="\/teams\/' . $nick . '\/2022\.shtml">.*/', $teamPage, $matches);
    
    if (empty($matches[0])) {
        preg_match_all('/<a\shref="\/teams\/' . $nick1 . '\/2022\.shtml">.*/', $teamPage, $matches); 
    }

    $statsText = trim(str_replace([',,', '%'], [',', ''], strip_tags(str_replace('</', ',</',$matches[0][2]))), ',');
    $stats = explode(',', $statsText);
    array_shift($stats);
    array_shift($stats);
    array_shift($stats);

    return $stats;
}

function getPitcherAdvancedStats(string $profileUrl, string $name, bool $isHome): array
{
    $stats = [];

    logMessage("   " . ($isHome ? "home" : "away") . " Pithcer is " . $name);
    $pitcherPage = getContentFrom($profileUrl);

    // get tr
    preg_match_all('/<tr\sid="pitching_advanced.2022".*/', $pitcherPage, $matches);

    $statsText = trim(str_replace([',,', '%'], [',', ''], strip_tags(str_replace('</', ',</',$matches[0][0]))), ',');
    $stats = explode(',', $statsText);
    array_shift($stats);
    array_shift($stats);
    array_shift($stats);
    array_shift($stats);

    return $stats;
};

?>