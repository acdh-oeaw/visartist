<?php

function getArtworkData($artworkID) {
  $file = file_get_contents("data/metadata.json");
  $data = json_decode($file, true);
  if ($artworkID == "all") {
    return $data["artworks"];
  }
  foreach($data["artworks"] as $artwork) {
    if ($artwork["id"] == $artworkID) {
      return $artwork;
    }
  }
}

//Add new artwork meta data
function addArtworkData($formData) {
  $file = file_get_contents("data/metadata.json");
  $data = json_decode($file, true);
  $latestId = $data["latestId"];
  $newId = $latestId + 1;

  savePixelColors( $_POST['url'], $newId );
  $paletteColors = getHistogramPalette($newId);

  //Check if artwork with this url exists
  foreach($data["artworks"] as $artwork) {
    if ($artwork["imgURL"] == $formData["url"]) {
      return "Artwork exists";
    }
  }

  $data["latestId"] = $newId;
  array_push($data["artworks"], array('id' => $newId, 'artist' => $formData["artist"], 'title' => $formData["title"], 'year' => $formData["year"], 'imgURL' => $formData["url"], 'medium' => $formData["medium"], 'dimensions' => $formData["dimensions"], 'currentLocation' => $formData["location"], 'rgbPalette' => $paletteColors));
  $new_data = json_encode($data);
  if (file_put_contents("data/metadata.json", $new_data) === false){
    unset($new_data);
    return false;
  } else {
    unset($new_data);
    return "Artwork metadata added.";
  }
}

function getArtwork3DPlot($artworkID) {

  return "
    <script>
    Plotly.d3.csv('data/".$artworkID.".csv', function(err, rows){

      function unpack(rows, key) {
        return rows.map(function(row) {
          return row[key]; 
        });
      }

      function dotcolor(rows) {
        return rows.map(function(row) { 
          return 'rgb('+row['r']+','+row['g']+','+row['b']+')'; 
        });
      }

      var data = {
        x: unpack(rows, 'r'), y: unpack(rows, 'g'), z: unpack(rows, 'b'),
        mode: 'markers',
        marker: {
          size: 2,
          color: dotcolor(rows),
          line: {
            color: 'rgb(90, 90, 90)',
            width: 0.1
          }
        },
        type: 'scatter3d'
      };

      var layout = {
        margin: { l:0, r:0, b: 0, t: 0 },
        scene:{ 
          camera: {
            center: { x: 0, y: 0, z: 0 }, 
            eye: { x: -1, y: 1.75, z: 0 }, 
            up: { x: 0, y: 1, z: 0 }
          },
          xaxis: {
            title: 'Red',
            tickfont: {
              color:'red',
            },
          },
          yaxis: {
            title: 'Green',
            tickfont: {
              color:'green',
            },
          },
          zaxis: {
            title: 'Blue',
            tickfont: {
              color:'blue',
            },
          }
        },
      };

      Plotly.newPlot('#artwork-plot', [data], layout);

    });
    </script>
  ";

}

function savePixelColors($img, $newId) {

  $MAX_WIDTH    = 150;
  $MAX_HEIGHT   = 150;

  $size = GetImageSize($img);
  $scale = 1;
  if ($size[0]>0) {
    $scale = min($MAX_WIDTH/$size[0], $MAX_HEIGHT/$size[1]);
    if ($scale < 1) {
      $width = floor($scale*$size[0]);
      $height = floor($scale*$size[1]);
    } else {
      $width = $size[0];
      $height = $size[1];
    }
  }
  $image_resized = imagecreatetruecolor($width, $height);
  if ($size[2] == 1) $image_orig = imagecreatefromgif($img);
  if ($size[2] == 2) $image_orig = imagecreatefromjpeg($img);
  if ($size[2] == 3) $image_orig = imagecreatefrompng($img);

  imagecopyresampled($image_resized, $image_orig, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
  $im = $image_resized;

  $imgWidth = imagesx($im);
  $imgHeight = imagesy($im);
  $colorData = array();
  $total_pixel_count = 0;
  
  for ($y=0; $y < $imgHeight; $y++) {
    for ($x=0; $x < $imgWidth; $x++) {

      $colorIndex = imagecolorat($im, $x, $y);
      $colorRGB = imagecolorsforindex($im, $colorIndex);
      $colorData[] = [$colorRGB['red'],$colorRGB['green'],$colorRGB['blue'],$x,$y];

    }
  }

  $csv = "r,g,b,x,y\n";//Column headers
  foreach ($colorData as $record){
      $csv.= $record[0].','.$record[1].','.$record[2].','.$record[3].','.$record[4]."\n"; //Append data to csv
  }

  $csv_handler = fopen('data/'.$newId.'.csv','w');
  fwrite ($csv_handler,$csv);
  fclose ($csv_handler);
}

function getHistogramPalette($artworkID) {

  $bucketPerDimension = 2;
  $bucketMax = 256;
  $bucketSize = $bucketMax / $bucketPerDimension;

  $csvFile = file('data/'.$artworkID.'.csv');
  $buckets = array();
  $allPixelsSize = 0;
  foreach ($csvFile as $line => $pixel) {
    if ($line > 0) {
      $allPixelsSize++;
      $pixel = str_getcsv($pixel);
      $pixelBucketIndex = getPixelBucketIndex($pixel, $bucketSize);
      if (!array_key_exists($pixelBucketIndex, $buckets)) {
        $buckets[$pixelBucketIndex] = array();
      }
      array_push($buckets[$pixelBucketIndex], $pixel);
    }
  }
  $averageColors = computeBucketsAverageColor($artworkID, $buckets);

  usort($averageColors, function($a, $b) {
    return $b[3] - $a[3];
  });

  foreach ($averageColors as $i => $averageColor) {
    $averageColors[$i][3] = number_format(($averageColor[3]/$allPixelsSize), 4);
  }
  return $averageColors;
}

function getPixelBucketIndex($pixel, $bucketSize) {
  $redBucket = floor($pixel[0] / $bucketSize);
  $greenBucket = floor($pixel[1] / $bucketSize);
  $blueBucket = floor($pixel[2] / $bucketSize);
  $pixelBucketIndex = $redBucket.":".$greenBucket.":".$blueBucket;
  return $pixelBucketIndex;
}

function computeBucketsAverageColor($artworkID, $buckets) {
  // Save mini color partition images
  $csvFile = file("data/".$artworkID.".csv");
  $endPixel = explode(",", end($csvFile));
  $width = (int)$endPixel[3];
  $height = (int)$endPixel[4];

  $averageColors = array();
  foreach ($buckets as $bucketIndex => $bucket) {
    $im = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $white);

    $bucketSize = 0;
    $redSum = 0;
    $blueSum = 0;
    $greenSum = 0;
    foreach ($bucket as $key => $pixel) {
      $bucketSize++;
      $redSum += $pixel[0];
      $blueSum += $pixel[1];
      $greenSum += $pixel[2];

      $color = imagecolorallocate($im, $pixel[0], $pixel[1], $pixel[2]);
      imagesetpixel($im , $pixel[3] , $pixel[4] , $color);
    }
    $bucketAverageRed = round($redSum / $bucketSize);
    $bucketAverageBlue = round($blueSum / $bucketSize);
    $bucketAverageGreen = round($greenSum / $bucketSize);
    array_push($averageColors, [$bucketAverageRed,$bucketAverageBlue,$bucketAverageGreen,$bucketSize]);

    $hexColor = sprintf("%02x%02x%02x", $bucketAverageRed, $bucketAverageBlue, $bucketAverageGreen);
    $save = "data/".$artworkID."-".$hexColor.".png";
    imagepng($im, $save);
  }
  return $averageColors;
}

?>