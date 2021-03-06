<?php
/*PhpDoc:
title: cmpgan.php - confrontation des données de localisation de mapcat avec celles du GAN
doc: |
  L'objectif est d'identifier les écarts entre mapcat et le GAN pour
    - s'assurer que mapcat est correct
    - marquer dans mapcat dans le champ badGan l'écart

  Le traitement dans le GAN des excroissances de cartes est hétérogène.
  Parfois l'extension spatiale du GAN les intègre et parfois elle ne les intègre pas.
journal: |
  2/7/2022:
    - reprise après correction des GAN par le Shom à la suite de mon message
    - ajout comparaison des échelles
  24/6/2022:
    - migration 
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../sgupdt/lib/gebox.inc.php';

use Symfony\Component\Yaml\Yaml;

// Classe stockant le contenu du fichier mapcat.yaml telquel et définissant de la méthode cmpGans
class MapCat {
  protected string $mapid;
  protected array $map;
  static array $maps=[]; // [$mapid => MapCat]
  
  static function init(array $mapcat): void {
    foreach ($mapcat['maps'] as $mapid => $map) {
      //echo "<pre>$mapid -> "; print_r($map);
      //if ($mapid=='FR7133')
      //if ($mapid=='FR7052')
      //if ($mapid=='FR6835')
      self::$maps[$mapid] = new MapCat($mapid, $map);
    }
  }
  
  function scale(): ?string { // formatte l'échelle comme dans le GAN
    return '1 : '.str_replace('.',' ',$this->map['scaleDenominator']);
  }
  
  function insetScale(int $i): ?string { // formatte l'échelle comme dans le GAN
    return '1 : '.str_replace('.',' ',$this->map['insetMaps'][$i]['scaleDenominator']);
  }
  
  static function cmpGans() {
    echo "<table border=1><th>mapid</th><th>badGan</th><th>inset</th>",
      "<th>cat'scale</th><th>gan'scale</th><th>ok?</th>",
      "<th>cat'SW</th><th>gan'SW</th><th>ok?</th>",
      "<th>x</th><th>cat'NE</th><th>gan'NE</th><th>ok?</th>\n";
    foreach (self::$maps as $mapid => $map) {
      //echo "<pre>"; print_r($map); echo "</pre>";
      $gan = Gan::$gans[substr($mapid, 2)];
      //echo "<pre>"; print_r($gan); echo "</pre>";
      if (isset($map->map['spatial']) && isset($gan->spatial['SW']) && $gan->spatial['SW'] && isset($gan->spatial['NE'])) {
        $ganspatial = [
          'SW' => str_replace('—', '-', $gan->spatial['SW']),
          'NE' => str_replace('—', '-', $gan->spatial['NE']),
        ];
        $mapspatial = $map->map['spatial'];
        //echo "<pre>"; print_r($map); echo "</pre>";
        if (isset($map->map['badGan']) || ($map->scale() <> $gan->scale)
            || ($mapspatial['SW'] <> $ganspatial['SW']) || ($mapspatial['NE'] <> $ganspatial['NE'])) {
          echo "<tr><td>$mapid</td><td>",$map->map['badGan'] ?? '',"</td><td></td>";
          echo "<td>",$map->scale(),"</td><td>",$gan->scale,"</td>",
            "<td>",($map->scale() == $gan->scale) ? 'ok' : '<b>KO</b>',"</td>\n";
          echo "<td>$mapspatial[SW]</td><td>$ganspatial[SW]</td>",
            "<td>",($mapspatial['SW'] == $ganspatial['SW']) ? 'ok' : '<b>KO</b',"</td>";
          echo "<td></td><td>$mapspatial[NE]</td><td>$ganspatial[NE]</td>",
            "<td>",($mapspatial['NE'] == $ganspatial['NE']) ? 'ok' : '<b>KO</b',"</td>";
          echo "</tr>\n";
        }
      }
      foreach ($map->map['insetMaps']  ?? [] as $i => $insetMap) {
        try {
          $ganpart = Gan::$gans[substr($mapid, 2)]->inSet(GBox::fromShomGt($insetMap['spatial']));
          $ganpartspatial = [
            'SW' => str_replace('—', '-', $ganpart->spatial['SW']),
            'NE' => str_replace('—', '-', $ganpart->spatial['NE']),
          ];
          if (($ganpart->scale <> $map->insetScale($i))
             || ($ganpartspatial['SW'] <> $insetMap['spatial']['SW'])
             || ($ganpartspatial['NE'] <> $insetMap['spatial']['NE'])) {
            echo "<tr><td>$mapid</td><td>",$map->map['badGan'] ?? '',"</td><td>$insetMap[title]</td>";
            //echo "<td><pre>"; print_r($insetMap); echo "</pre></td>";
            echo "<td>",$map->insetScale($i),"</td><td>",$ganpart->scale,"</td>",
              "<td>",($ganpart->scale == $map->insetScale($i)) ? 'ok' : '<b>KO</b>',"</td>";
            echo "<td>",$insetMap['spatial']['SW'],"\n";
            //echo "<td><pre>"; print_r($ganpart); echo "</pre></td>";
            echo "<td>$ganpartspatial[SW]</td>",
              "<td>",$ganpartspatial['SW'] == $insetMap['spatial']['SW'] ? 'ok' : '<b>KO</b>',"</td>";
            echo "<td></td><td>",$insetMap['spatial']['NE'],"\n";
            echo "<td>$ganpartspatial[NE]</td>",
              "<td>",$ganpartspatial['NE'] == $insetMap['spatial']['NE'] ? 'ok' : '<b>KO</b>',"</td>";
            echo "</tr>\n";
          }
        }
        catch (SExcept $e) {
        }
      }
    }
    echo "</table>\n";
  }
  
  function __construct(string $mapid, array $map) {
    $this->mapid = $mapid;
    $this->map = $map;
  }
};
MapCat::init(Yaml::parseFile(__DIR__.'/mapcat.yaml'));
//echo '<pre>maps='; print_r(MapCat::$maps);

/*PhpDoc: classes
name: GanInSet
title: class GanInSet - description d'un cartouche dans la synthèse d'une carte
*/
class GanInSet {
  protected string $title;
  public string $scale;
  public array $spatial; // sous la forme ['SW'=> sw, 'NE'=> ne]
  
  function __construct(string $html) {
    //echo "html=$html\n";
    if (!preg_match('!^\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*{div}\s*([^{]*){/div}\s*$!', $html, $matches))
      throw new Exception("Erreur de construction de GanInSet sur '$html'");
    $this->title = trim($matches[1]);
    $this->spatial = ['SW'=> trim($matches[2]), 'NE'=> trim($matches[3])];
  }
  
  function asArray(): array {
    return [
      'title'=> $this->title,
      'spatial'=> $this->spatial,
    ];
  }
};

/*PhpDoc: classes
name: Gan
title: class Gan - synthèse des GAN par carte à la date de moisson des GAN ou indication d'erreur d'interrogation des GAN
doc: |
*/
class Gan {
  const GAN_DIR = __DIR__.'/gan';
  const PATH = __DIR__.'/../dashboard/gans.'; // chemin sans extension des fichiers stockant la synthèse en pser ou en yaml,
  const PATH_PSER = self::PATH.'pser'; // chemin du fichier stockant le catalogue en pser
  const PATH_YAML = self::PATH.'yaml'; // chemin du fichier stockant le catalogue en  Yaml
  static string $hvalid=''; // intervalles des dates de la moisson des GAN
  static array $gans=[]; // dictionnaire [$mapnum => Gan]
  
  protected string $mapnum;
  protected ?string $groupTitle=null; // sur-titre optionnel identifiant un ensemble de cartes
  protected string $title=''; // titre
  public ?string $scale=null; // échelle
  protected ?string $edition=null; // edition
  protected array $corrections=[]; // liste des corrections
  public array $spatial=[]; // sous la forme ['SW'=> sw, 'NE'=> ne]
  protected array $inSets=[]; // cartouches
  protected array $analyzeErrors=[]; // erreurs éventuelles d'analyse du résultat du moissonnage
  protected string $valid; // date de moissonnage du GAN en format ISO
  protected string $harvestError=''; // erreur éventuelle du moissonnage

  static function week(string $modified): string { // transforme une date en semaine sur 4 caractères comme utilisé par le GAN 
    $time = strtotime($modified);
    return substr(date('o', $time), 2) . date('W', $time);
  }
  
  // retourne le cartouche correspondant au qgbox
  function inSet(GBox $qgbox): GanInSet {
    //echo "<pre>"; print_r($this);
    $dmin = 9e999;
    $pmin = -1;
    foreach ($this->inSets as $pnum => $part) {
      //try {
        $pgbox = GBox::fromShomGt([
          'SW'=> str_replace('—','-', $part->spatial['SW']),
          'NE'=> str_replace('—','-', $part->spatial['NE'])]);
      /*}
      catch (SExcept $e) {
        echo "<pre>SExcept::message: ",$e->getMessage(),"\n";
        //print_r($this);
        return null;
      }*/
      $d = $qgbox->distance($pgbox);
      //echo "pgbox=$pgbox, dist=$d\n";
      if ($d < $dmin) {
        $dmin = $d;
        $pmin = $pnum;
      }
    }
    if ($pmin == -1)
      throw new SExcept("Aucun Part");
    return $this->inSets[$pmin];
  }
    
  static function loadFromPser() { // charge les données depuis le pser 
    $contents = unserialize(file_get_contents(self::PATH_PSER));
    self::$hvalid = $contents['valid'];
    self::$gans = $contents['gans'];
  }
};

echo "<!DOCTYPE HTML><html><head><title>cmpgan</title></head><body>\n";

Gan::loadFromPser(); // charge les GANs sepuis le fichier gans.pser du dashboard
//echo '<pre>gans='; print_r(Gan::$gans);

MapCat::cmpGans();
