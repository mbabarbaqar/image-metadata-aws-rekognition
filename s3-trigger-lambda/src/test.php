<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);




//--------------------------- image conversion --------------------------
$bucketName = "cwsassets-test";
$objectName = "media.bank.works/Black.tiff";

$credentials = new Aws\Credentials\Credentials("AKIAXJX75T3IH2FPHN27", "A/z9XVQYYVu5ySHO7wU7gVCEcc8CqK5GPMudECcg");


$client = new Aws\S3\S3Client([ 'region' => 'eu-west-1', 'version' => 'latest', "credentials" => $credentials]);
// Register the stream wrapper from an S3Client object
$client->registerStreamWrapper();


if (isset($_GET['test']) && $_GET['test'] == 1) {
    $client->registerStreamWrapper();
    $client->copyObject([
        'Bucket' => $bucketName,
        'Key' => $objectName,
        'CopySource' => $bucketName . '/' . $objectName,
        'MetadataDirective' => 'REPLACE',
        'Metadata'=> [
            'recognition' =>  json_encode(["test" => "testing"])
        ]
    ]);

    exit("copied");
}

if (isset($_GET['test']) && $_GET['test'] == 1.2) {

    $client->registerStreamWrapper();

    $result = $client->headObject([
            'Bucket' => $bucketName,
            'Key' => $objectName
    ]);

    var_dump("<pre>", json_decode($result['Metadata']['recognition']));exit();

    exit("copied");
}

if (isset($_GET['test']) && $_GET['test'] == 2) {
    //-------------------Remove file after generating meta data
    $pathInfo = pathinfo($objectName);
    var_dump($pathInfo);exit();

    $imageName = $pathInfo['filename'];

    $image = "s3://$bucketName/$objectName";
    $directories = explode("/", $pathInfo['dirname']);
    if (in_array("temp-converted", $directories)) {
        unlink($image);
    }

    exit("file removed");
}


//------------------------ read image using imagick and convert to png or jpg  -------
if (isset($_GET['test']) && $_GET['test'] == 3) {
    $client->registerStreamWrapper();
    $data = file_get_contents("s3://$bucketName/$objectName");
    $imgagick = new Imagick();
    $imgagick->readImageBlob($data);
    $imgagick->setFormat("png");

    $client->putObject([
        "Body" => $imgagick->getImageBlob(),
        "Bucket" => "image-meta",
        "ContentType" => 'image/png',
        "Key" => "copy/test.png"
    ]);

    exit("done");
}

if (isset($_GET['test']) && $_GET['test'] == 4) {
    //$client->createImageBuilder();

    $data = file_get_contents("s3://$bucketName/$objectName");

    $image = imagecreatefromstring($data);

    ob_start();
    imagepng($image);

    $pngImageData = ob_get_contents();
    ob_end_clean();


    header('Content-type: image/png');
    echo $pngImageData;
}



// ------------------ MySQL Connection using pdo-mysql
if (isset($_GET['test']) && $_GET['test'] == 5) {
    //Local server
    //$pdo = new PDO(
    //    'mysql:host=mysql;port=3306;dbname=cs2',
    //    'cs2',
    //    '65432'
    //);

    //Test Server
    $pdo = new PDO(
        'mysql:host=aws-aurora-80-clustor.cluster-chiqs2xwc447.eu-west-1.rds.amazonaws.com;port=3306;dbname=cs2',
        'admin',
        '$dxNLJx80Q58'
    );

    $stmt = $pdo->prepare("SELECT count(*) FROM User");
    $stmt->execute();

    $rows = $stmt->fetchAll();

    var_dump($rows);

    unset($stmt);

    exit();
}


//------------------------- MySQL connection using mysqli php
if (isset($_GET['test']) && $_GET['test'] == 6) {
    $mysqli = new mysqli('mysql', 'cs2', '65432', 'cs2', '3306');

    // Check connection
    if ($mysqli->connect_errno) {
        echo "Failed to connect to MySQL: " . $mysqli->connect_error;
        exit();
    }

    // Perform query
    if ($result = $mysqli->query("SELECT * FROM User")) {
        echo "Returned rows are: " . $result->num_rows;
        // Free result set
        $result->free_result();
    }

    $mysqli->close();

    exit();
}

if (isset($_GET['test']) && $_GET['test'] == 7) {
    echo "<img src='$data' alt='img' >";exit();

    $client->putObject([
        "Body" => $data,
        "Bucket" => "image-meta",
        "ContentType" => "image/jpeg",
        "Key" => "copy/test.jpg"
    ]);


    exit("Completed");

    //$object = $bucket->object('image/bird.jpg');
    //
    //try{
    //    $imagick = new \Imagick('s3://'.$bucketName.'/'.$objectName);
    //}
    //catch (Exception $e) {
    //    //
    //}
    //
    //if($imagick){
    //    $contentType=$imagick->getImageMimeType();
    //
    //    //$imagick->scaleImage($width,$height);
    //
    //    header("Content-type: ".$contentType);
    //    echo $imagick->getImageBlob();
    //
    //    $client->putObject([
    //        'ContentType' => 'image/jpeg',
    //        'Body' =>$imagick->getImageBlob(),
    //        'Bucket' => $bucketName,
    //        'Key' => "resized/$objectName",
    //        'StorageClass' => 'REDUCED_REDUNDANCY',
    //        'Tagging' => 'thumbnail=yes'
    //    ]);
    //    die();
    //}
}


