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
use BitmovinApiSdk\Models\AudioAdaptationSet;
use BitmovinApiSdk\Models\AudioMediaInfo;
use BitmovinApiSdk\Models\CloudRegion;
use BitmovinApiSdk\Models\CodecConfigType;
use BitmovinApiSdk\Models\DashFmp4Representation;
use BitmovinApiSdk\Models\DashManifest;
use BitmovinApiSdk\Models\DashRepresentationType;
use BitmovinApiSdk\Models\DashRepresentationTypeMode;
use BitmovinApiSdk\Models\Encoding;
use BitmovinApiSdk\Models\EncodingOutput;
use BitmovinApiSdk\Models\Fmp4Muxing;
use BitmovinApiSdk\Models\IngestInputStream;
use BitmovinApiSdk\Models\ManifestResource;
use BitmovinApiSdk\Models\H264VideoConfiguration;
use BitmovinApiSdk\Models\HlsManifest;
use BitmovinApiSdk\Models\ManifestGenerator;
use BitmovinApiSdk\Models\MessageType;
use BitmovinApiSdk\Models\MuxingStream;
use BitmovinApiSdk\Models\Output;
use BitmovinApiSdk\Models\Period;
use BitmovinApiSdk\Models\PresetConfiguration;
use BitmovinApiSdk\Models\ProfileH264;
use BitmovinApiSdk\Models\S3Input;
use BitmovinApiSdk\Models\S3Output;
use BitmovinApiSdk\Models\StartEncodingRequest;
use BitmovinApiSdk\Models\StartManifestRequest;
use BitmovinApiSdk\Models\Status;
use BitmovinApiSdk\Models\Stream;
use BitmovinApiSdk\Models\StreamInfo;
use BitmovinApiSdk\Models\StreamInput;
use BitmovinApiSdk\Models\StreamMode;
use BitmovinApiSdk\Models\StreamSelectionMode;
use BitmovinApiSdk\Models\Task;
use BitmovinApiSdk\Models\VideoAdaptationSet;
use BitmovinApiSdk\Models\Webhook;
use BitmovinApiSdk\Models\WebhookHttpMethod;

const TEST_NAME = "h264-aac-fmp4-hls-dash-vod-standard-with-webhook";

const BITMOVIN_API_KEY="{YOUR API KEY}";
const BITMOVIN_TENANT_ORG_ID="{YOUR ORG ID}";

const S3_INPUT_BUCKET_NAME="{YOUR INPUT S3 BUCKET NAME}";
const S3_INPUT_ACCESS_KEY="{YOUR INPUT S3 BUCKET ACCESS KEY}";
const S3_INPUT_SECRET_KEY="{YOUR INPUT S3 BUCKET SECRET KEY}";

const INPUT_PATH = "AWOLNATION_muxed.mkv";

const S3_OUTPUT_BUCKET_NAME="{YOUR OUTPUT S3 BUCKET NAME}";
const S3_OUTPUT_ACCESS_KEY="{YOUR INPUT S3 BUCKET ACCESS KEY}";
const S3_OUTPUT_SECRET_KEY="{YOUR INPUT S3 BUCKET SECRET KEY}";

const OUTPUT_BASE_PATH = "output/" . TEST_NAME . "/";

const WEBHOOK_ENDPOINT = "{YOUR WEBHOOK ENDPOINT}";  # https://webhook.site/xxx

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
    ['bitrate' => 64000, 'rate' => 44100.0, 'channel_layout' => AacChannelLayout::CL_STEREO()]
];

try {
    // Create Bitmovin API instance using your Bitmovin account's API key
    $bitmovin_api = new BitmovinApi(Configuration::create()
        ->apiKey(BITMOVIN_API_KEY)
        ->tenantOrgId(BITMOVIN_TENANT_ORG_ID)
        ->logger(new ConsoleLogger));

    // Create Input and Output
    $input = $bitmovin_api->encoding->inputs->s3->create((new S3Input)
        ->bucketName(S3_INPUT_BUCKET_NAME)
        ->accessKey(S3_INPUT_ACCESS_KEY)
        ->secretKey(S3_INPUT_SECRET_KEY));
    $output = $bitmovin_api->encoding->outputs->s3->create((new S3Output)
        ->bucketName(S3_OUTPUT_BUCKET_NAME)
        ->accessKey(S3_OUTPUT_ACCESS_KEY)
        ->secretKey(S3_OUTPUT_SECRET_KEY));

    // Create an Encoding instance
    $encoding = $bitmovin_api->encoding->encodings->create((new Encoding)
        ->name(TEST_NAME)
        ->cloudRegion(CloudRegion::AWS_AP_NORTHEAST_1())
        ->encoderVersion('STABLE'));

    // Create Webhook for FINISHED and ERROR
    $finished_webhook = $bitmovin_api->notifications->webhooks->encoding->encodings->finished->createByEncodingId($encoding->id, (new Webhook)
        ->url(WEBHOOK_ENDPOINT)
        ->method(WebhookHttpMethod::POST()));
    $error_webhook = $bitmovin_api->notifications->webhooks->encoding->encodings->error->createByEncodingId($encoding->id, (new Webhook)
        ->url(WEBHOOK_ENDPOINT)
        ->method(WebhookHttpMethod::POST()));

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
            ->minKeyframeInterval(2)
            ->maxKeyframeInterval(2)
            ->refFrames(4)
            ->bframes(3)
            ->maxBitrate($video_profile['bitrate'] * 1.2)
            ->minBitrate($video_profile['bitrate'] * 0.8)
            ->bufsize($video_profile['bitrate'] * 2)
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

        $bitmovin_api->encoding->encodings->muxings->fmp4->create($encoding->id, (new Fmp4Muxing())
            ->name("Video FMP4 Muxing {$video_profile['height']}p")
            ->streams([(new MuxingStream)->streamId($video_stream->id)])
            ->outputs([$video_muxing_output])
            ->segmentLength(6)
            ->initSegmentName('init.mp4')
            ->segmentNaming('segment_%number%.m4s'));
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

        $bitmovin_api->encoding->encodings->muxings->fmp4->create($encoding->id, (new Fmp4Muxing())
            ->name(sprintf('Audio MP4 Muxing %s kbps', $audio_profile['bitrate']/1000))
            ->streams([(new MuxingStream)->streamId($audio_stream->id)])
            ->outputs([$audio_muxing_output])
            ->segmentLength(6)
            ->initSegmentName('init.mp4')
            ->segmentNaming('segment_%number%.m4s'));
    }

    // Create a custom HLS and DASH manifest
    $hls_manifest = createHlsManifest($encoding, $output, "");
    $dash_manifest = createDashManifest($encoding, $output, "");

    // Start Encoding job
    executeEncoding($encoding, (new StartEncodingRequest)
        ->manifestGenerator(ManifestGenerator::V2())
        ->vodDashManifests([(new ManifestResource)->manifestId($dash_manifest->id)])
        ->vodHlsManifests([(new ManifestResource)->manifestId($hls_manifest->id)])
    );
} catch (Exception $exception) {
    echo $exception;
}

/**
 * Create an HLS manifest using Fmp4 muxing output.
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

    $fmp4_muxings = $bitmovin_api->encoding->encodings->muxings->fmp4->list($encoding->id);
    foreach ($fmp4_muxings->items as $muxing) {
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
 * Create a DASH manifest using Fmp4 muxing output.
 */
function createDashManifest(Encoding $encoding, Output $output, string $output_path): DashManifest
{
    global $bitmovin_api;

    $manifest_output = (new EncodingOutput)
        ->outputId($output->id)
        ->outputPath(buildAbsolutePath($output_path))
        ->acl([(new AclEntry)
            ->permission(AclPermission::PUBLIC_READ())]);

    $dash_manifest = $bitmovin_api->encoding->manifests->dash->create((new DashManifest)
        ->manifestName('stream.mpd')
        ->outputs([$manifest_output])
        ->name('Dash manifest'));

    $period = $bitmovin_api->encoding->manifests->dash->periods->create($dash_manifest->id, (new Period));
    $video_adaptation_set = $bitmovin_api->encoding->manifests->dash->periods->adaptationsets->video->create($dash_manifest->id, $period->id, (new VideoAdaptationSet));
    $audio_adaptation_set = $bitmovin_api->encoding->manifests->dash->periods->adaptationsets->audio->create($dash_manifest->id, $period->id, (new AudioAdaptationSet)->lang('en'));

    $fmp4_muxings = $bitmovin_api->encoding->encodings->muxings->fmp4->list($encoding->id);
    foreach ($fmp4_muxings->items as $muxing) {
        $stream = $bitmovin_api->encoding->encodings->streams->get($encoding->id, $muxing->streams[0]->streamId);

        if (strpos($stream->mode->getValue(), 'PER_TITLE_TEMPLATE')) {
            continue;
        }

        $codec = $bitmovin_api->encoding->configurations->type->get($stream->codecConfigId);
        $segmentPath = substr($muxing->outputs[0]->outputPath, strlen(OUTPUT_BASE_PATH));
        if ($codec->type == CodecConfigType::AAC()) {
            $bitmovin_api->encoding->manifests->dash->periods->adaptationsets->representations->fmp4->create(
                $dash_manifest->id,
                $period->id,
                $audio_adaptation_set->id,
                (new DashFmp4Representation)
                    ->encodingId($encoding->id)
                    ->muxingId($muxing->id)
                    ->type(DashRepresentationType::TEMPLATE())
                    ->mode(DashRepresentationTypeMode::TEMPLATE_REPRESENTATION())
                    ->segmentPath($segmentPath)
            );
        } elseif ($codec->type == CodecConfigType::H264()) {
            $bitmovin_api->encoding->manifests->dash->periods->adaptationsets->representations->fmp4->create(
                $dash_manifest->id,
                $period->id,
                $video_adaptation_set->id,
                (new DashFmp4Representation)
                    ->encodingId($encoding->id)
                    ->muxingId($muxing->id)
                    ->type(DashRepresentationType::TEMPLATE())
                    ->mode(DashRepresentationTypeMode::TEMPLATE_REPRESENTATION())
                    ->segmentPath($segmentPath)
            );
        }
    }

    return $dash_manifest;
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
