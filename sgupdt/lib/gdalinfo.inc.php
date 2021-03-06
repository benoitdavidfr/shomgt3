<?php
/*PhpDoc:
title: gdalinfo.inc.php - Analyse une JSON fabriqué par GDAL INFO et en extrait les infos essentielles
name: gdalinfo.inc.php
classes:
doc: |
  Les infos essentielles sur la taille size et si le fichier est géréférencé son ebox et son gbox
journal: |
  6/6/2022:
    - réécriture pour fonctionner à la fois en GDAL 2 et en GDAL3 ; utilise la sortie de gdalinfo -json
  22/5/2022:
    - modif. utilisation EnVar
  3/5/2022:
    - utilisation de la variable d'environnement SHOMGT3_MAPS_DIR_PATH
    - chagt du paramètre de GdalInfo::__construct()
  28/4/2022:
    - correction d'un bug
    - traitement du cas où le Tiff n'est pas géoréférencé alors seul size est renseigné
  24/4/2022:
    - définition de la classe GdalInfo
  18-20/4/2022:
    - création pour répondre aux besoins de décodage des infos CRS liées aux cartes Shom
*/
$VERSION[basename(__FILE__)] = date(DATE_ATOM, filemtime(__FILE__));

require_once __DIR__.'/sexcept.inc.php';
require_once __DIR__.'/envvar.inc.php';
require_once __DIR__.'/gebox.inc.php';

class GeoJsonPolygon { /// GeoJSON Polygon
  const ErrorBadType = 'Polygon::ErrorBadType';
  protected $coordinates;
  
  function __construct(array $def) {
    if ($def['type']<>'Polygon')
      throw new SExcept("Erreur, type erroné", self::ErrorBadType);
    $this->coordinates = $def['coordinates'][0];
  }
  
  function gbox(): GBox {
    $gbox = new GBox;
    foreach ($this->coordinates as $c)
      $gbox->bound($c);
    return $gbox;
  }
};

/*PhpDoc: classes
title: class GdalInfo - gère l'extraction des infos d'un fichier généré par gdalinfo
name: GdalInfo
doc: |
  La méthode __construct() lit le fichier, en extrait les infos intéressantes et les stocke dans l'objet ainsi créé
  Les autres méthodes extraient des infos de l'objet.
*/
class GdalInfo {
  const ErrorFileNotFound = 'GdalInfo::ErrorFileNotFound';
  const ErrorNoMatch = 'GdalInfo::ErrorNoMatch';

  protected array $size; // ['width'=>{width}, 'height'=> {height}]
  protected ?GBox $gbox=null; // le GBox issu du gdalinfo ou null si aucun gbox n'est défini
  protected ?EBox $ebox=null; // le EBox issu du gdalinfo
  
  static function dms2Dec(string $val): float { // transforme "9d20'26.32\"E" ou "42d38'39.72\"N" en degrés décimaux
    if (!preg_match('!^(\d+)d([\d ]+)\'([\d .]+)"(E|W|N|S)$!', $val, $matches))
      throw new Exception("No match for \"$val\" in ".__FILE__." ligne ".__LINE__);
    return (in_array($matches[4],['E','N']) ? +1 : -1) * ($matches[1] + ($matches[2] + $matches[3]/60)/60);
  }
  
  // retourne le chemin du fichier info.json correspondant à un gtname, temp indique sir la carte est dans temp ou dans maps
  static function filepath(string $gtname, bool $temp): string {
    return sprintf('%s/%s/%s.info.json',
      EnvVar::val('SHOMGT3_MAPS_DIR_PATH').($temp ? '/../temp' : ''),
      substr($gtname, 0, 4), $gtname);
  }
  
  function size(): array { return $this->size; }
  function ebox(): ?EBox { return $this->ebox; }

  function __construct(string $filename) { // extraction des infos générées par gdalinfo
    if (!is_file($filename))
      throw new SExcept("file '$filename' not found in GdalInfo", self::ErrorFileNotFound);
    $info = json_decode(file_get_contents($filename), true);
    //WmsServer::log("Dans GdalInfo::__construct(), info=".json_encode($info, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  
    if (!isset($info['size']))
      throw new SExcept("No match for Size", self::ErrorNoMatch);
    $this->size = ['width'=> $info['size'][0], 'height'=> $info['size'][1]];
  
    // si le champ coordinateSystem n'est pas défini alors le fichier n'est pas géoréférencé
    if (!isset($info['coordinateSystem']) || !isset($info['coordinateSystem']['wkt']) || !$info['coordinateSystem']['wkt'])
      return;

    $this->ebox = new EBox([
      $info['cornerCoordinates']['lowerLeft'][0],
      $info['cornerCoordinates']['lowerLeft'][1],
      $info['cornerCoordinates']['upperRight'][0],
      $info['cornerCoordinates']['upperRight'][1],
    ]);
    
    $wgs84Extent = new GeoJsonPolygon($info['wgs84Extent']);
    $this->gbox = $wgs84Extent->gbox();
  }

  function asArray(): array {
    return [
      'size'=> $this->size,
      'gbox'=> $this->gbox ? $this->gbox->asArray() : null,
      'ebox'=> $this->ebox ? $this->ebox->asArray() : null,
    ];
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire


require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/geotiffs.inc.php';

use Symfony\Component\Yaml\Yaml;

//echo "<pre>$info\n";
echo "<pre>\n";

if (1) { // Test sur tous les GéoTiffs
  print_r(geotiffs());
  foreach (geotiffs() as $gtname) {
    $gdalInfo = new GdalInfo(GdalInfo::filepath($gtname, false));
    echo Yaml::dump([$gtname => $gdalInfo->asArray()]);
  }
}
else {
  $gtname = '6670_pal300';
  $gdalInfo = new GdalInfo(GdalInfo::filepath($gtname, false));
  echo Yaml::dump([$gtname => $gdalInfo->asArray()]);
}