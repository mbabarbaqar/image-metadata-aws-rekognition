<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Bref\Context\Context;
use Bref\Event\S3\S3Event;
use Bref\Event\S3\S3Handler;
use GuzzleHttp\Promise;

if (isset($_GET['test'])) {
    require __DIR__ . '/test.php';
    exit();
}

class Handler extends S3Handler
{
    private \Aws\S3\S3Client $s3Client;
    private array $conf;
    private array $database;
    private bool $convertImage;

    public function __construct(){
        $this->conf = [
            "version" => "latest",
            "region" => "eu-west-1"
        ];

        $this->database = [
            'host' => '{host-url}',
            'port' => 3306,
            'name' => 'cs2',
            'username' => '{username}',
            'password' => '{password}'
        ];

        $this->s3Client = new Aws\S3\S3Client($this->conf);
    }

    public function handleS3(S3Event $event, Context $context): void
    {
        /**
         * We should probably use some specifically set up IAM role as credential
         */
        $bucket = $event->getRecords()[0]?->getBucket()?->getName();
        $key = $event->getRecords()[0]?->getObject()?->getKey();

        $location = $key;

        if (!$bucket || !$key) return;

        # Skip thumbnails
        if (str_contains($key, '_thumbnail')) {
            echo "\n Skipped the thumbnail file ($key).";
            return;
        }

        echo "Bucket Name: " . $bucket . ", File Name: $key";

        $pathInfo = pathinfo($key);
        $extension = strtolower($pathInfo['extension']);
        $imageName = $pathInfo['filename'];

        if ($extension != "jpeg" && $extension != "jpg" && $extension != "png") {

            # Working with image conversions
            $this->convertImageFormat($bucket, $key);

            echo "\n Format ($extension) not supported.";
            return;
        }

        # Save Image Meta
        # Detect converted image
        # In case if we are updating metadata of the same image instead of saving in other places.
        if (str_contains($imageName, '-ext-')) {

            $imageParts = explode("-ext-", $imageName);

            $actualExtension = $imageParts[0];
            $location = str_replace([$actualExtension."-ext-", ".".$extension], ["", ".".$actualExtension], $key);
        }

        try {
            $imageMeta = $this->getRekognitionData($bucket, $key);

            if ($imageMeta) {

                # 1. Save Image Meta
                # Update meta of the image
                $this->s3Client->copyObject([
                    'Bucket' => $bucket,
                    'Key' => "images-meta/" . $location,
                    'CopySource' => $bucket . '/' . $key,
                    'MetadataDirective' => 'REPLACE',
                    'Metadata'=> [
                        'recognition' =>  json_encode($imageMeta)
                    ]
                ]);

                # 2. Save meta in json format
                $this->s3Client->putObject([
                    "ACL" => "private",
                    "Body" => json_encode($imageMeta),
                    "Bucket" => $bucket,
                    "ContentEncoding" => "UTF-8",
                    "ContentType" => "application/json",
                    "Key" => "image-meta-json/" . $imageName . "_metadata.json"
                ]);

                # 3. Save metadata to database against the location of the image.
                $this->saveImageMeta($imageMeta, $location);
            }

            # Working with image conversions
            # Remove the newly converted image
            $this->convertImageFormat($bucket, $key, true);

        } catch (Exception) {
            $imageMeta = [];
        } catch (Throwable $e) {
            //
        }

    }

    /**
     * Description: Get rekognition data from any image
     * @param string $bucketName
     * @param string $fileName
     * @return array
     * @throws Throwable
     */
    private function getRekognitionData(string $bucketName, string $fileName): array
    {
        $imageData = [];

        $rekognition = new Aws\Rekognition\RekognitionClient($this->conf);

        try {
            $labelsPromise = $rekognition->detectLabelsAsync([
                "Image" => [
                    "S3Object" => [
                        "region" => "eu-west-1",
                        "Bucket" => $bucketName,
                        "Name" => $fileName,
                    ],
                ],
                "MaxLabels" => 10,
                "MinConfidence" => 85.00
            ]);
        } catch (Exception){
            $labelsPromise = [];
        }

        try {
            $facesPromise = $rekognition->detectFacesAsync([
                "Attributes" => ["ALL"],
                "Image" => [
                    "S3Object" => [
                        "Bucket" => $bucketName,
                        "Name" => $fileName,
                    ],
                ],
            ]);
        } catch (Exception){
            $facesPromise = [];
        }

        try {
            $celebrityPromise = $rekognition->recognizeCelebritiesAsync([
                "Image" => [
                    "S3Object" => [
                        "Bucket" => $bucketName,
                        "Name" => $fileName,
                    ],
                ],
            ]);
        }catch (Exception){
            $celebrityPromise = [];
        }

        $promises = [
            "labels" => $labelsPromise,
            "faces" => $facesPromise,
            "celebrities" => $celebrityPromise,
        ];

        $results = Promise\unwrap($promises);
        $labelsResult = $results["labels"];
        $facesResult = $results["faces"];
        $celebrityResult = $results["celebrities"];

        if ($labelsResult->hasKey("Labels")) {
            $labels = $labelsResult->get("Labels");
            $labelsList = array_map(function ($label) {
                return $label["Name"];
            }, $labels);
            $imageData["labels"] = $labelsList or [];
        }

        if ($facesResult->hasKey("FaceDetails")) {
            $faceDetails = $facesResult->get("FaceDetails");
            $facesList = array_map(function ($faceProperties) {
                $face = [];
                foreach ($faceProperties as $key => $value) {
                    $identifiersToCatch = [
                        "Eyeglasses",
                        "Sunglasses",
                        "Beard",
                        "Mustache",
                        "Smile",
                        "AgeRange",
                        "Gender",
                    ];
                    if (in_array($key, $identifiersToCatch) && (!array_key_exists("Value", $value) || $value["Value"] !== false)) {
                        $face[$key] = $value;
                    }
                }
                return $face;
            }, $faceDetails);
            $imageData["face"] = $facesList or [];
        }

        if ($celebrityResult->hasKey("CelebrityFaces")) {
            $celebrityList = [];
            $celebrities = $celebrityResult->get("CelebrityFaces");
            foreach ($celebrities as $key => $celeb) {
                array_push($celebrityList, $celeb["Name"]);
            }
            $imageData["celebrities"] = $celebrityList;
        }

        return $imageData;
    }

    /**
     * Description: Convert and unsupported image format to jpg, so that in next trigger recognition data will work fine. After, it will delete temp image.
     * @param string $bucket
     * @param string $key
     * @param bool $remove | "Pass true if going to delete an object from s3 bucket."
     */
    private function convertImageFormat(string $bucket, string $key, bool $remove = false)
    {
        # Register the stream wrapper from an S3Client object
        $this->s3Client->registerStreamWrapper();

        $pathInfo = pathinfo($key);
        $extension = $pathInfo['extension'];
        $imageName = str_replace($extension, "jpeg", $pathInfo['basename']);

        $convertPath = "";
        if (!empty($pathInfo['dirname'])) {
            $convertPath = $pathInfo['dirname'] . "/";
        }

        # Remove file after generating meta data
        if ($remove == true) {
            $directories = explode("/", $pathInfo['dirname']);
            if (in_array("temp-converted", $directories)) {
                $this->s3Client->deleteMatchingObjects($bucket, $key);
            }
            return;
        }

        try{

            $data = file_get_contents("s3://$bucket/$key");
            $imageData = new Imagick();
            $imageData->readImageBlob($data);
            $imageData->setFormat("jpg");

            if($imageData->valid()){
                $this->s3Client->putObject([
                    'ContentType' => 'image/jpeg',
                    'Body' => $imageData->getImageBlob(),
                    'Bucket' => $bucket,
                    'Key' => $convertPath . "temp-converted/$extension-ext-$imageName",
                    'StorageClass' => 'REDUCED_REDUNDANCY',
                    'Tagging' => 'thumbnail=yes'
                ]);
            }
        }
        catch (Exception $e) {
            echo "Image not found.... \n";
        }
    }

    /**
     * Description: Save rekognition data to MediaFile table in database
     * @param object | array $data | "Image rekognition data"
     */
    private function saveImageMeta(object|array $data, string $location)
    {
        $pdo = new PDO(
            'mysql:host='.$this->database['host'].';port='.$this->database['host'].';dbname='.$this->database['name'],
            $this->database['username'],
            $this->database['password']
        );

        $stmt = $pdo->prepare("UPDATE {table} SET image_meta=:image_meta WHERE id='$location'");

        $stmt->execute([
            ':image_meta' => json_encode($data)
        ]);

        unset($stmt);
    }
}

/**
 *Initiate Function
*/
return new Handler();
