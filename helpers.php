<?php 
declare(strict_types = 1);

function logMessage(string $message): void
{
    echo ">>> " . $message . PHP_EOL;
}

function getContentFrom(string $url): bool|string
{
    // create curl resource
    $ch = curl_init();

    // set url
    curl_setopt($ch, CURLOPT_URL, $url);

    //return the transfer as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // $output contains the output string
    $output = curl_exec($ch);

    // close curl resource to free up system resources
    curl_close($ch);   

    return $output;
}

?>