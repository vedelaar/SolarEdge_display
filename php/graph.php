<?php

class curlclass
{
    private $lasturl;
    private $curl;

    function __CONSTRUCT($header=true)
    {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HEADER, $header);
        curl_setopt($this->curl, CURLOPT_PROXY, @$_SERVER["http_proxy"]);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.0; SLCC1; .NET CLR 2.0.50727; Media Center PC 5.0; .NET CLR 3.0.04506; InfoPath.2)");
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, 1);
    }

    function getpage($page)
    {
        curl_setopt($this->curl, CURLOPT_URL, $page);
        $data = curl_exec($this->curl);
        return $data;
    }

    function postpage($page,$postfields)
    {
        curl_setopt($this->curl, CURLOPT_POST, true);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postfields);
        $data=$this->getpage($page);
        curl_setopt($this->curl, CURLOPT_POST, false);
        return $data;
    }
}

class SolarEdge
{
  public $curl;
  public $siteId;
  public $apiKey;

  public function __construct($siteId, $apiKey) {
    $this->curl = new curlclass(false);
    $this->siteId = $siteId;
    $this->apiKey = $apiKey;
  }

  // example: site/{siteid}/inventory
  public function get($path) {
    $path = str_replace('{siteId}', $this->siteId, $path);
    $url = 'https://monitoringapi.solaredge.com/'.$path;
    if(strpos($path, '?') === false)
      $url .= "?";
    else
      $url .= '&';
    $url.="api_key=".$this->apiKey;
    $url.='&version=1.0.0';
    //echo $url."\n";
    $data = $this->curl->getpage($url);
    //var_dump($data);
    return json_decode($data,true);
  }

  public function getInventory() {
    return $this->get('site/{siteId}/inventory');
  }

  public function getInverterFromInventory($inventory,$num) {
    return $inventory['Inventory']['inverters'][$num] ?? null;
  }

  public function getSiteDetails() {
    return $this->get('site/{siteId}/details');
  }

  // Current production, production today, production this month, production this year, production lifetime
  public function getSiteOverview() {
    return $this->get('site/{siteId}/overview');
  }

  public function getSiteDataPeriod() {
    return $this->get('site/{siteId}/dataPeriod');
  }

  // timeunit = QUARTER_OF_AN_HOUR | HOUR | DAY | WEEK | MONTH | YEAR
  // date = yyyy-mm-dd
  public function getSiteEnergy($startDate, $endDate, $timeUnit) {
    return $this->get('site/{siteId}/energy?timeUnit='.$timeUnit.'&startDate='.$startDate.'&endDate='.$endDate);
  }

  public function getSitePowerDetails($startTime, $endTime) {
    return $this->get('site/{siteId}/powerDetails?startTime='.urlencode($startTime).'&endTime='.urlencode($endTime));
  }

  public function getInverterTechnicalData($inverter, $startTime, $endTime) {
    return $this->get('equipment/{siteId}/'.$inverter['SN'].'/data?startTime='.urlencode($startTime).'&endTime='.urlencode($endTime));
  }

}

//echo date("Y-m-d H:i:s");

//$x = new SolarEdge('','');
//$details = $x->getSiteOverview();
//$sdp = $x->getSiteDataPeriod();
//$se = $x->getSiteEnergy('2022-12-26', '2022-12-27', 'QUARTER_OF_AN_HOUR');
//print_r($se);
//$se = $x->getSitePowerDetails('2022-12-26 11:11:11', '2022-12-27 12:12:12');
//$inventory = $x->getInventory();
//$inverter = $x->getInverterFromInventory($inventory, 0);
//$se = $x->getInverterTechnicalData($inverter, '2022-12-26 11:11:11', '2022-12-27 11:11:11');
//print_r($se);
//$x->getEnergyForGraph();



class Display
{
  public $solarEdge;
  public $gd;
  public $black;
  public $white;
  public $gray;
  public $font = "./LiberationSans-Bold.ttf";

  public $canvas_width = 960;
  public $canvas_height = 540;

  public function __construct($solarEdge, $w = 960, $h = 540) {
    $this->solarEdge = $solarEdge;
    $this->canvas_width = $w;
    $this->canvas_height = $h;
    $this->gd = @imagecreate($this->canvas_width, $this->canvas_height)
        or die("Cannot Initialize new GD image stream");
    imageantialias($this->gd, true);
    $this->black = imagecolorallocate($this->gd, 0, 0, 0);
    $this->white = imagecolorallocate($this->gd, 255, 255, 255);
    $this->gray = imagecolorallocate($this->gd, 148,148,148);
  }

  public function draw($id1, $id2) {
  }

  // https://www.php.net/manual/en/function.imagettfbbox.php
  public function calculateTextBox($text,$fontFile,$fontSize,$fontAngle) {
      /************
      simple function that calculates the *exact* bounding box (single pixel precision).
      The function returns an associative array with these keys:
      left, top:  coordinates you will pass to imagettftext
      width, height: dimension of the image you have to create
      *************/
      $rect = imagettfbbox($fontSize,$fontAngle,$fontFile,$text);
      $minX = min(array($rect[0],$rect[2],$rect[4],$rect[6]));
      $maxX = max(array($rect[0],$rect[2],$rect[4],$rect[6]));
      $minY = min(array($rect[1],$rect[3],$rect[5],$rect[7]));
      $maxY = max(array($rect[1],$rect[3],$rect[5],$rect[7]));

      return array(
       "left"   => abs($minX) - 1,
       "top"    => abs($minY) - 1,
       "width"  => $maxX - $minX,
       "height" => $maxY - $minY,
       "box"    => $rect
      );
  }

  public function getEnergyForGraph() {
    $from = date("Y-m-d", strtotime("yesterday"));
    $to = date("Y-m-d");
    $arr = [];
    $se = $this->solarEdge->getSiteEnergy($from, $to, 'QUARTER_OF_AN_HOUR');
    $vals = [];
    $keys = [];
    foreach($se['energy']['values'] as $val) {
      $vals[] = $val['value'] | 0;
      $keys[] = $val['date'];
    }

require_once ('jpgraph/jpgraph.php');
require_once ('jpgraph/jpgraph_line.php');
//require_once ('jpgraph/jpgraph_bar.php');


 // Width and height of the graph
$width = 960; $height = 540;

// Create a graph instance
$graph = new Graph($width,$height);
$theme_class = new BwTheme;

// Specify what scale we want to use,
// int = integer scale for the X-axis
// int = integer scale for the Y-axis
$graph->SetScale('intint');

$graph->SetTheme($theme_class);
// Setup a title for the graph
$graph->title->Set('Energie van zonnepanelen');

// Setup titles and X-axis labels
//$graph->xaxis->title->Set('(year from 1701)');
$graph->xaxis->SetTickLabels($keys);
$graph->xaxis->SetTextLabelInterval(5,10);
$graph->xaxis->HideLastTickLabel();
$graph->xaxis->moveabit = 60;

// Setup Y-axis title
//$graph->yaxis->title->Set('(# sunspots)');

// Create the linear plot
$lineplot=new LinePlot($vals);
//$lineplot->SetFillColor('black');

// Add the plot to the graph
$graph->Add($lineplot);

// Display the graph
$this->gd = $graph->Stroke('__handle');
//$graph->Stroke();
    //$begin = new DateTime($from.' 00:00:00');
    //$end = new DateTime($to.' 23:59:59');

    //$interval = new DateInterval('PT15M');
    //$period = new DatePeriod($begin, $interval, $end);
    //foreach ($period as $dt) {
    //  echo $dt->format("Y-m-d H:i:s\n");
    //}
  }

  public function toCppData() {
    for($y=0; $y<$this->canvas_height; $y++) {
      $byte = 0;
      $done = true;
      for($x=0; $x<$this->canvas_width; $x++) {
        $pix = imagecolorat($this->gd, $x, $y);
        $colors = imagecolorsforindex($this->gd, $pix);
        $col = (($colors["red"] + $colors["green"] + $colors["blue"]) / 3) >> 4;
        //print_r($colors);
        //echo "$col\n";
        if ($x%2 == 0) {
          $byte = $col;
          $done = false;
        } else {
          $byte |= ($col << 4);
          echo pack("C",$byte);
          //echo "0x".bin2hex(pack("C",$byte)).", ";
          $done = true;
        }
      }
      if (!$done)
          echo pack("C",$byte);
          //echo "0x".bin2hex(pack("C",$byte)).", ";
      //echo "\n";
    }
  }

  public function toImage() {
    imagepng($this->gd, "img.png");
    imagedestroy($this->gd);
  }
}


$se = new SolarEdge('','');
$display = new Display($se);
$display->getEnergyForGraph();

ob_start();
$x->toCppData();
header("Content-length: ".ob_get_length());
ob_end_flush();

$x->toImage();


