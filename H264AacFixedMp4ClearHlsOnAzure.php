<?php

require './vendor/autoload.php';

use BitmovinApiSdk\BitmovinApi;
use BitmovinApiSdk\Common\BitmovinApiException;
use BitmovinApiSdk\Common\Logging\ConsoleLogger;
use BitmovinApiSdk\Configuration;
use BitmovinApiSdk\Models\AacAudioConfiguration;
use BitmovinApiSdk\Models\AacChannelLayout;
use BitmovinApiSdk\Models\AclEntry;
use BitmovinApiSdk\Models\AclPermission;
use BitmovinApiSdk\Models\AudioMediaInfo;
use BitmovinApiSdk\Models\AzureInput;
use BitmovinApiSdk\Models\AzureOutput;
use BitmovinApiSdk\Models\CloudRegion;
use BitmovinApiSdk\Models\CodecConfigType;
use BitmovinApiSdk\Models\Encoding;
use BitmovinApiSdk\Models\EncodingOutput;
use BitmovinApiSdk\Models\FragmentedMp4MuxingManifestType;
use BitmovinApiSdk\Models\IngestInputStream;
use BitmovinApiSdk\Models\Mp4Muxing;
use BitmovinApiSdk\Models\H264VideoConfiguration;
use BitmovinApiSdk\Models\HlsManifest;
use BitmovinApiSdk\Models\ManifestGenerator;
use BitmovinApiSdk\Models\MessageType;
use BitmovinApiSdk\Models\MuxingStream;
use BitmovinApiSdk\Models\Output;
use BitmovinApiSdk\Models\PresetConfiguration;
use BitmovinApiSdk\Models\ProfileH264;
use BitmovinApiSdk\Models\StartEncodingRequest;
use BitmovinApiSdk\Models\StartManifestRequest;
use BitmovinApiSdk\Models\Status;
use BitmovinApiSdk\Models\Stream;
use BitmovinApiSdk\Models\StreamInfo;
use BitmovinApiSdk\Models\StreamInput;
use BitmovinApiSdk\Models\StreamMode;
use BitmovinApiSdk\Models\StreamSelectionMode;
use BitmovinApiSdk\Models\Task;

const TEST_NAME = "h264-aac-fixed-mp4-clear-hls-on-azure";

const BITMOVIN_API_KEY="{YOUR API KEY}";
const BITMOVIN_TENANT_ORG_ID="{YOUR ORG ID}";

const AZURE_INPUT_ACCOUNT_NAME="{YOUR INPUT AZURE ACCOUNT NAME}";
const AZURE_INPUT_ACCOUNT_KEY="{YOUR INPUT AZURE ACCOUNT KEY}";
const AZURE_INPUT_CONTAINER_NAME="{YOUR INPUT AZURE CONTAINER NAME}";

const INPUT_PATH = "{PATH TO YOUR INPUT FILE}"; // "big_buck_bunny_1080p_h264.mov"

const AZURE_OUTPUT_ACCOUNT_NAME="{YOUR OUTPUT AZURE ACCOUNT NAME}";
const AZURE_OUTPUT_ACCOUNT_KEY="{YOUR OUTPUT AZURE ACCOUNT KEY}";
const AZURE_OUTPUT_CONTAINER_NAME="{YOUR OUTPUT AZURE CONTAINER NAME}";
const OUTPUT_BASE_PATH = "output/" . TEST_NAME . "/";

// Define the required video and audio profiles
/**
 * @var array<int, array{
 *     height: int,
 *     bitrate: int,
 *     profile: ProfileH264,
 *     mode: StreamMode,
 *     preset: PresetConfiguration
 * }> $video_encoding_profiles
 */
$video_encoding_profiles = [
    ['height' => 360, 'bitrate' => 512000, 'profile' => ProfileH264::MAIN(),
        'mode' => StreamMode::STANDARD(), 'preset' => PresetConfiguration::VOD_STANDARD()],
    ['height' => 450, 'bitrate' => 1000000, 'profile' => ProfileH264::MAIN(),
        'mode' => StreamMode::STANDARD(), 'preset' => PresetConfiguration::VOD_STANDARD()],
    ['height' => 720, 'bitrate' => 2000000, 'profile' => ProfileH264::HIGH(),
        'mode' => StreamMode::STANDARD(), 'preset' => PresetConfiguration::VOD_STANDARD()]
];
/**
 * @var array<int, array{
 *     bitrate: int,
 *     rate: float,
 *     channel_layout: AacChannelLayout
 * }> $audio_encoding_profiles
 */
$audio_encoding_profiles = [
    ['bitrate' => 96000, 'rate' => 44100.0, 'channel_layout' => AacChannelLayout::CL_STEREO()]
];

try {
    // Create Bitmovin API instance using your Bitmovin account's API key
    $bitmovin_api = new BitmovinApi(Configuration::create()
        ->apiKey(BITMOVIN_API_KEY)
        ->tenantOrgId(BITMOVIN_TENANT_ORG_ID)
        ->logger(new ConsoleLogger));

    // Create Input and Output
    $input = $bitmovin_api->encoding->inputs->azure->create((new AzureInput)
        ->accountName(AZURE_INPUT_ACCOUNT_NAME)
        ->accountKey(AZURE_INPUT_ACCOUNT_KEY)
        ->container(AZURE_INPUT_CONTAINER_NAME));
    $output = $bitmovin_api->encoding->outputs->azure->create((new AzureOutput)
        ->accountName(AZURE_OUTPUT_ACCOUNT_NAME)
        ->accountKey(AZURE_OUTPUT_ACCOUNT_KEY)
        ->container(AZURE_OUTPUT_CONTAINER_NAME));

    // Create an Encoding instance
    $encoding = $bitmovin_api->encoding->encodings->create((new Encoding)
        ->name(TEST_NAME)
        ->cloudRegion(CloudRegion::AZURE_JAPAN_EAST())
        ->encoderVersion('BETA'));

    // Create an input streams for each video and audio
    $video_ingest_input_stream = $bitmovin_api->encoding->encodings->inputStreams->ingest->create(
        $encoding->id,
        (new IngestInputStream)
            ->inputId($input->id)
            ->inputPath(INPUT_PATH)
            ->selectionMode(StreamSelectionMode::VIDEO_RELATIVE())
            ->position(0)
    );
    $audio_ingest_input_stream = $bitmovin_api->encoding->encodings->inputStreams->ingest->create(
        $encoding->id,
        (new IngestInputStream)
            ->inputId($input->id)
            ->inputPath(INPUT_PATH)
            ->selectionMode(StreamSelectionMode::AUDIO_RELATIVE())
            ->position(0)
    );
    $video_input_stream = (new StreamInput())->inputStreamId($video_ingest_input_stream->id);
    $audio_input_stream = (new StreamInput())->inputStreamId($audio_ingest_input_stream->id);

    // For each video profile, create codec config, stream and muxing
    foreach ($video_encoding_profiles as $video_profile) {
        $h264_codec = $bitmovin_api->encoding->configurations->video->h264->create((new H264VideoConfiguration)
            ->name("Video Codec {$video_profile['height']}p")
            ->height($video_profile['height'])
            ->bitrate($video_profile['bitrate'])
            ->profile($video_profile['profile'])
            ->presetConfiguration($video_profile['preset']));

        $video_stream = $bitmovin_api->encoding->encodings->streams->create($encoding->id, (new Stream)
            ->name("Video Stream {$video_profile['height']}p")
            ->codecConfigId($h264_codec->id)
            ->inputStreams([$video_input_stream])
            ->mode($video_profile['mode']));

        $video_muxing_output = (new EncodingOutput)
            ->outputId($output->id)
            ->outputPath(OUTPUT_BASE_PATH . 'video/' .  $video_profile['height'])
            ->acl([(new AclEntry)
                ->permission(AclPermission::PUBLIC_READ())]);

        $bitmovin_api->encoding->encodings->muxings->mp4->create($encoding->id, (new Mp4Muxing)
            ->name("Video MP4 Muxing {$video_profile['height']}p")
            ->streams([(new MuxingStream)->streamId($video_stream->id)])
            ->outputs([$video_muxing_output])
            ->filename("video_{$video_profile['height']}.mp4")
            ->fragmentDuration(6000)
            ->fragmentedMP4MuxingManifestType(FragmentedMp4MuxingManifestType::HLS_BYTE_RANGES()));
    }

    // For each audio profile, create codec config, stream and muxing
    foreach ($audio_encoding_profiles as $audio_profile) {
        $aac_codec = $bitmovin_api->encoding->configurations->audio->aac->create((new AacAudioConfiguration)
            ->name(sprintf('Audio Codec %s kbps', $audio_profile['bitrate']/1000))
            ->bitrate($audio_profile['bitrate'])
            ->rate($audio_profile['rate'])
            ->channelLayout($audio_profile['channel_layout']));

        $audio_stream = $bitmovin_api->encoding->encodings->streams->create($encoding->id, (new Stream)
            ->name(sprintf('Audio Stream %s kbps', $audio_profile['bitrate']/1000))
            ->codecConfigId($aac_codec->id)
            ->inputStreams([$audio_input_stream])
            ->mode(StreamMode::STANDARD()));

        $audio_muxing_output = (new EncodingOutput)
            ->outputId($output->id)
            ->outputPath(OUTPUT_BASE_PATH . 'audio/' .  $audio_profile['bitrate'])
            ->acl([(new AclEntry)
                ->permission(AclPermission::PUBLIC_READ())]);

        $bitmovin_api->encoding->encodings->muxings->mp4->create($encoding->id, (new Mp4Muxing)
            ->name(sprintf('Audio MP4 Muxing %s kbps', $audio_profile['bitrate']/1000))
            ->streams([(new MuxingStream)->streamId($audio_stream->id)])
            ->outputs([$audio_muxing_output])
            ->filename(sprintf('audio_%s.mp4', $audio_profile['bitrate']/1000))
            ->fragmentDuration(6000)
            ->fragmentedMP4MuxingManifestType(FragmentedMp4MuxingManifestType::HLS_BYTE_RANGES()));
    }

    // Start Encoding job
    executeEncoding($encoding, (new StartEncodingRequest));

    // Create a custom HLS Manifest
    $hls_manifest = createHlsManifest($encoding, $output, "");

    // Start the HLS Manifest creation
    executeManifestGeneration($hls_manifest, (new StartManifestRequest)
        ->manifestGenerator(ManifestGenerator::V2()));
} catch (Exception $exception) {
    echo $exception;
}

/**
 * Create an HLS manifest using MP4 muxing output.
 *
 * <p>API endpoints:
 * https://developer.bitmovin.com/encoding/reference/postencodingmanifestshls
 * https://developer.bitmovin.com/encoding/reference/postencodingmanifestshlsmediaaudiobymanifestid
 * https://developer.bitmovin.com/encoding/reference/postencodingmanifestshlsstreamsbymanifestid
 *
 * @param Encoding $encoding The encoding to be started
 * @param Output $output The output to which the manifest should be written
 * @param string $output_path The path to which the manifest should be written
 * @throws BitmovinApiException
 * @throws Exception
 */
function createHlsManifest(Encoding $encoding, Output $output, string $output_path): HlsManifest
{
    global $bitmovin_api;

    $manifest_output = (new EncodingOutput)
        ->outputId($output->id)
        ->outputPath(buildAbsolutePath($output_path))
        ->acl([(new AclEntry)
            ->permission(AclPermission::PUBLIC_READ())]);

    $hls_manifest = $bitmovin_api->encoding->manifests->hls->create((new HlsManifest)
        ->manifestName('stream.m3u8')
        ->outputs([$manifest_output])
        ->name('Hls manifest'));

    $mp4_muxings = $bitmovin_api->encoding->encodings->muxings->mp4->list($encoding->id);
    foreach ($mp4_muxings->items as $muxing) {
        $stream = $bitmovin_api->encoding->encodings->streams->get($encoding->id, $muxing->streams[0]->streamId);

        if (strpos($stream->mode->getValue(), 'PER_TITLE_TEMPLATE')) {
            continue;
        }

        $codec = $bitmovin_api->encoding->configurations->type->get($stream->codecConfigId);
        $segmentPath = substr($muxing->outputs[0]->outputPath, strlen(OUTPUT_BASE_PATH));
        if ($codec->type == CodecConfigType::AAC()) {
            $audioCodec = $bitmovin_api->encoding->configurations->audio->aac->get($stream->codecConfigId);

            $bitmovin_api->encoding->manifests->hls->media->audio->create($hls_manifest->id, (new AudioMediaInfo)
                ->name('HLS Audio Media')
                ->groupId('AUDIO')
                ->language('en')
                ->segmentPath($segmentPath)
                ->encodingId($encoding->id)
                ->muxingId($muxing->id)
                ->streamId($stream->id)
                ->uri(sprintf('audio_%s.m3u8', $audioCodec->bitrate/1000)));
        } elseif ($codec->type == CodecConfigType::H264()) {
            $videoCodec = $bitmovin_api->encoding->configurations->video->h264->get($stream->codecConfigId);

            $bitmovin_api->encoding->manifests->hls->streams->create($hls_manifest->id, (new StreamInfo)
                ->audio('AUDIO')
                ->closedCaptions('NONE')
                ->segmentPath($segmentPath)
                ->uri(sprintf('video_%s.m3u8', $videoCodec->height))
                ->encodingId($encoding->id)
                ->streamId($stream->id)
                ->muxingId($muxing->id));
        }
    }

    return $hls_manifest;
}

/**
 * Starts the actual encoding process and periodically polls its status until it reaches a final
 * state
 *
 * <p>API endpoints:
 * https://developer.bitmovin.com/encoding/reference/postencodingencodingsstartbyencodingid
 * https://developer.bitmovin.com/encoding/reference/getencodingencodingsstatusbyencodingid
 *
 * <p>Please note that you can also use our webhooks API instead of polling the status. For more
 * information consult the API spec:
 * https://bitmovin.com/docs/encoding/api-reference/sections/notifications-webhooks
 *
 * @param Encoding $encoding The encoding to be started
 * @param StartEncodingRequest $start_encoding_request The request object to be sent with the start call
 * @throws BitmovinApiException
 * @throws Exception
 */
function executeEncoding(Encoding $encoding, StartEncodingRequest $start_encoding_request)
{
    global $bitmovin_api;

    $bitmovin_api->encoding->encodings->start($encoding->id, $start_encoding_request);

    do {
        sleep(5);
        $task = $bitmovin_api->encoding->encodings->status($encoding->id);
        echo 'Encoding status is ' . $task->status . ' (progress: ' . $task->progress . ' %)' . PHP_EOL;
    } while ($task->status != Status::FINISHED()
    && $task->status != Status::ERROR()
    && $task->status != Status::CANCELED());

    if ($task->status == Status::ERROR()) {
        logTaskErrors($task);
        throw new Exception('Encoding failed');
    }

    if ($task->status == Status::CANCELED()) {
        logTaskErrors($task);
        throw new Exception('Encoding cancelled');
    }

    echo 'Encoding finished successfully' . PHP_EOL;
}

/**
 * Starts the actual manifest creation process and periodically polls its status until it reaches a final
 * state
 *
 * <p>API endpoints:
 * https://developer.bitmovin.com/encoding/reference/postencodingmanifestshlsstartbymanifestid
 * https://developer.bitmovin.com/encoding/reference/getencodingmanifestshlsstatusbymanifestid
 *
 * @param HlsManifest $hls_manifest The manifest to be created
 * @param StartManifestRequest $start_manifest_request The request object to be sent with the start call
 * @throws BitmovinApiException
 * @throws Exception
 */
function executeManifestGeneration(HlsManifest $hls_manifest, StartManifestRequest $start_manifest_request)
{
    global $bitmovin_api;

    $bitmovin_api->encoding->manifests->hls->start($hls_manifest->id, $start_manifest_request);

    do {
        sleep(5);
        $task = $bitmovin_api->encoding->manifests->hls->status($hls_manifest->id);
        echo 'Manifest status is ' . $task->status . ' (progress: ' . $task->progress . ' %)' . PHP_EOL;
    } while ($task->status != Status::FINISHED() && $task->status != Status::ERROR());

    if ($task->status == Status::ERROR()) {
        logTaskErrors($task);
        throw new Exception('Manifest generation failed');
    }

    echo 'Manifest> finished successfully' . PHP_EOL;
}

function buildAbsolutePath(string $relativePath): string
{
    return OUTPUT_BASE_PATH . trim($relativePath, DIRECTORY_SEPARATOR);
}

function logTaskErrors(Task $task)
{
    if ($task->messages == null) {
        return;
    }

    $messages = array_filter($task->messages, function ($msg) {
        return $msg->type == MessageType::ERROR();
    });

    foreach ($messages as $message) {
        echo $message->text . PHP_EOL;
    }
}
