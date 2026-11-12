<?php

declare(strict_types=1);
namespace App\Export;

/** Small static OOXML report, packaged by PHP's existing non-executable ZIP API. */
final class StockWorkbook
{
    private const XML='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    public function create(array $snapshot): string
    {
        $instant=(new \DateTimeImmutable($snapshot['captured_at']))->setTimezone(new \DateTimeZone($snapshot['timezone']));
        $store=$snapshot['store'];$when=$instant->format('d/m/Y H:i:s T');
        $headers=['Référence','Article','Catégorie','Casage','Quantité','Unité','Seuil bas','Seuil critique','Disponibilité','État de l’article','Codes-barres et QR codes','Description'];
        $rows=[[],[$this->text('Stock — '.$store['name'],1)],[$this->text('Magasin : '.$store['code'].' — État au '.$when,3)],[],array_map(fn(string $v):string=>$this->text($v,2),$headers)];
        foreach($snapshot['rows'] as $item) {
            $quantity=(int)$item['quantity'];$low=$item['low_threshold'];$critical=$item['critical_threshold'];
            $state=$quantity===0?'En rupture':($critical!==null&&$quantity<=(int)$critical?'Stock critique':($low!==null&&$quantity<=(int)$low?'Stock bas':'En stock'));
            $rows[]=[
                $this->text($item['sku']),$this->text($item['name']),$this->text($item['category']??''),$this->text($item['location']??''),$this->integer($quantity),$this->text('unité'),
                $low===null?'':$this->integer((int)$low),$critical===null?'':$this->integer((int)$critical),$this->text($state),$this->text($item['deleted_at']!==null?'Retiré du catalogue':($item['active']?'Actif':'Désactivé')),
                $this->text(implode("\n",$item['scan_codes'])),$this->text($item['description']),
            ];
        }
        $details=[[],[$this->text('Magasin',1)],[],[$this->text('Information',2),$this->text('Valeur',2)],
            [$this->text('Code'),$this->text($store['code'])],[$this->text('Nom'),$this->text($store['name'])],[$this->text('État'),$this->text($store['active']?'Actif':'Désactivé')],
            [$this->text('Adresse'),$this->text($store['address']??'')],[$this->text('Description'),$this->text($store['description']??'')],[$this->text('État au'),$this->text($when)],
            [$this->text('Nombre de références'),$this->integer(count($snapshot['rows']))],
            [$this->text('Périmètre'),$this->text('État actuel complet du magasin, sans historique. Les casages et seuils non renseignés restent vides.')],
            [$this->text('Grands entiers'),$this->text('Les entiers dépassant 15 chiffres sont conservés en texte afin de préserver leur valeur exacte dans Excel.')],
        ];
        $files=[
            '[Content_Types].xml'=>self::XML.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>self::XML.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>self::XML.'<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="Stock" sheetId="1" r:id="rId1"/><sheet name="Magasin" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>self::XML.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>$this->styles(),
            'xl/worksheets/sheet1.xml'=>$this->sheet($rows,[22,34,24,30,18,12,18,18,22,24,36,60],5,'L'),
            'xl/worksheets/sheet2.xml'=>$this->sheet($details,[26,100],4,'B'),
        ];
        $directory=sys_get_temp_dir().'/stock-xlsx-'.bin2hex(random_bytes(16));
        if(!mkdir($directory,0700)) {throw new \RuntimeException('export_temporary_directory_unavailable');}
        $path=$directory.'/stock.zip';
        try {
            $zip=new \PharData($path,0,null,\Phar::ZIP);
            foreach($files as $name=>$content) {$zip->addFromString($name,$content);}
            unset($zip);chmod($path,0600);
            $bytes=file_get_contents($path);if($bytes===false) {throw new \RuntimeException('export_unavailable');}return $bytes;
        } finally {if(is_file($path)) {unlink($path);}rmdir($directory);}
    }
    private function text(string $value,int $style=0): string
    {
        // Inline strings never become formulas, hyperlinks or executable spreadsheet content.
        $value=preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value);
        return ' t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
    }
    private function integer(int $value): string
    {
        return abs($value)>999999999999999?$this->text((string)$value):' t="n" s="4"><v>'.$value.'</v></c>';
    }
    private function sheet(array $rows,array $widths,int $header,string $lastColumn): string
    {
        $xml=self::XML.'<worksheet xmlns="'.self::NS.'"><dimension ref="A1:'.$lastColumn.count($rows).'"/><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane xSplit="1" ySplit="'.$header.'" topLeftCell="B'.($header+1).'" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="22"/><cols>';
        foreach($widths as $i=>$width) {$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';}
        $xml.='</cols><sheetData>';
        foreach($rows as $i=>$cells) {
            $row=$i+1;$height=$row===$header?34:($row===2?30:22);
            if($row>$header) {
                foreach($cells as $column=>$cell) {
                    $plain=html_entity_decode(strip_tags(substr($cell,strpos($cell,'>')+1)),ENT_XML1|ENT_QUOTES,'UTF-8');
                    $lines=0;foreach(explode("\n",$plain) as $line) {$lines+=max(1,(int)ceil(mb_strlen($line,'UTF-8')/max(8,$widths[$column]-3)));}
                    $height=max($height,min(409,16*$lines+8));
                }
            }
            $xml.='<row r="'.$row.'" ht="'.$height.'" customHeight="1">';
            foreach($cells as $column=>$cell) {if($cell!=='') {$xml.='<c r="'.chr(65+$column).$row.'"'.$cell;}}
            $xml.='</row>';
        }
        return $xml.'</sheetData><autoFilter ref="A'.$header.':'.$lastColumn.max($header,count($rows)).'"/><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }
    private function styles(): string
    {
        return self::XML.'<styleSheet xmlns="'.self::NS.'"><fonts count="4"><font><sz val="10"/><name val="Arial"/><color rgb="FF242424"/></font><font><b/><sz val="16"/><name val="Arial"/><color rgb="FF242424"/></font><font><b/><sz val="10"/><name val="Arial"/><color rgb="FF242424"/></font><font><i/><sz val="10"/><name val="Arial"/><color rgb="FF595959"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFF7900"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
