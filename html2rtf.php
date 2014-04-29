<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
/**
 * Library that transforms HTML docs/fragments to RTF text string
 *
 * @package    local
 * @subpackage html2rtf
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class with static functions that transform HTML 2 RTf text
 * @author 2011 The Open University
 *
 */
class html2rtf {
    /**
     * Converts string of HTML into full rtf document text and returns as string
     * Will throw moodle exceptions when errors are encountered
     * @param string $html HTML doc or fragment as (utf-8) string
     * @param array $rtfoptions Array of params/vals that are to be set in the xsl
     * @param array $images array of images as file objects that can be used by insert image.
     * @Returns String of rtf text
     */
    public static function convert($html, $rtfoptions = null, $images = null) {
        rtf_xslt_functions::$images = $images;
        $doc = new DOMDocument('1.0');

        //Load as HTML as this is more forgiving than an xml load
        if (!@$doc->loadHTML($html)) {
            throw new moodle_exception('Error converting the document - malformed HTML.');
        }

        // Recursively fix all text nodes to replace special characters.
        self::replace_non_ascii_text($doc->documentElement);

        // Get rid of html doctype.
        $newxml = preg_replace('~<!DOCTYPE html[^>]+>~', '', $doc->saveXML());

        // Sort out the xmlns attribute, for some reason we seem to get two of them
        $newxml = preg_replace('~<html[^>]*>~',
                '<html xmlns="http://www.w3.org/1999/xhtml">', $newxml);

        //Load string as new xml and set encoding to Windows-1252
        $doc->loadXML($newxml);
        $doc->encoding = 'Windows-1252';

        //Transform our xhtml data into rtf using xslt
        // Load XSL template
        $xsl = new DOMDocument();
        if (!$xsl->load(dirname(__FILE__).'/xhtml2rtf.xsl')) {
            throw new moodle_exception('Error loading xhtml2rtf stylesheet');
        }
        // Create new XSLTProcessor
        $xslt = new XSLTProcessor();
        // Load stylesheet
        if (!$xslt->importStylesheet($xsl)) {
            throw new moodle_exception('Error parsing xhtml2rtf stylesheet');
        }

        if (is_array($rtfoptions)) {
            foreach ($rtfoptions as $opt => $val) {
                $xslt->setParameter('', $opt, $val);
            }
        }

        if (!isset($rtfoptions['document-protected'])) {
            //Ensure document is not write protected
            $xslt->setParameter('', 'document-protected', '0');
        }
        $xslt->registerPHPFunctions();

        //result to string
        ob_start();
        $result = $xslt->transformToURI($doc, 'php://output');
        $rtf = ob_get_clean();

        //String replacement for colour table
        $rtf = str_replace('%%COLOURTABLE%%', rtf_xslt_functions::$coltableoutput, $rtf);

        return $rtf;
    }

    /**
     * Recursively searches all text nodes in the document for non-ASCII
     * characters. Internally, all text should be in UTF-8 format. The non-ASCII
     * characters will then be replaced by a <specialcharacter dec="12345"/>
     * tag.
     *
     * @param DOMNode $node
     */
    private static function replace_non_ascii_text(DOMNode $node) {
        if ($node->nodeType == XML_TEXT_NODE) {
            // If the string contains only ASCII, do nothing.
            $before = $node->nodeValue;
            if (preg_match('~^[\x00-\x7f]*$~', $before)) {
                return;
            }
            // Handle each character one at a time.
            $length = textlib::strlen($before);
            $currenttext = '';
            $doc = $node->ownerDocument;
            $parent = $node->parentNode;
            for ($pos = 0; $pos < $length; $pos++) {
                $char = textlib::substr($before, $pos, 1);
                if (strlen($char) == 1) {
                    // ASCII, add to text node.
                    $currenttext .= $char;
                } else {
                    // First add in already-retrieved text if any.
                    if ($currenttext !== '') {
                        $parent->insertBefore($doc->createTextNode($currenttext), $node);
                        $currenttext = '';
                    }
                    // Now add specialcharacter tag.
                    $ent = textlib::utf8_to_entities($char, true);
                    $tag = $doc->createElement('specialcharacter');
                    $tag->setAttribute('dec', preg_replace('~^&#([0-9]+);$~', '\1', $ent));
                    $parent->insertBefore($tag, $node);
                }
            }
            // Add in remaining text if any.
            if ($currenttext !== '') {
                $parent->insertBefore($doc->createTextNode($currenttext), $node);
                $currenttext = '';
            }
            // Remove original node.
            $parent->removeChild($node);
        } else if ($node->nodeType == XML_ELEMENT_NODE) {
            for ($child = $node->firstChild; $child; $child = $next) {
                $next = $child->nextSibling;
                self::replace_non_ascii_text($child);
            }
        }
    }
}

/**
 * Class of PHP functions used by XSL when creating RTF - DO NOT CALL FROM PHP!
 * Some of these functions have been converted to PHP from originals at
 * http://www.kartmann.org/freeware/XHTML2RTF/
 * @author 2011 The Open University
 *
 */
class rtf_xslt_functions {
    private static $multiplywidth = 192;
    private static $marginwidth = 196;
    private static $arrmaxlen;
    private static $arrtotlen;

    //Colour table array + text - used to store rtf colour table
    private static $existingcols = 5;//number of colours already in xsl
    private static $coltable = array();//array of colours that have been processed
    public static $coltableoutput = '';//colour table string to be added to output
    public static $images = '';//array of images available for use in output

    /**
     * Returns the number of a colour from the colour table
     * Will add colour to the table if not previously encountered
     * Supports hex colours only
     * @param string $stylecont contents of the css style declaration
     */
    public static function get_colour_num($stylecont) {
        //First get the colour from the style contents
        if (!$colval = preg_match('/#?(([a-fA-F0-9]){3}){1,2}$/', $stylecont, $matched)) {
            return 0;//no col val found
        }
        $colval = str_replace('#', '', $matched[0]);
        if (!$arpos = array_search($colval, self::$coltable)) {
            //Not previously encountered
            if ($rgb = self::hex_to_rgb($colval)) {
                self::$coltableoutput .= ';\red' . $rgb['red'] . '\green' . $rgb['green'];
                self::$coltableoutput .= '\blue' . $rgb['blue'];
                $newarpos = count(self::$coltable) + 1 + self::$existingcols;
                self::$coltable[$newarpos] = $colval;
                return $newarpos;
            } else {
                return 0;
            }
        } else {
            return ($arpos + self::$existingcols);
        }
    }

    /**
     * Convert a hexa decimal color code to its RGB equivalent
     * http://www.php.net/manual/en/function.hexdec.php#99478
     *
     * @param string $hexStr (hexadecimal color value)
     * @param boolean $returnAsString (if set true, returns the value separated by the separator character.
     * Otherwise returns associative array)
     * @param string $seperator (to separate RGB values. Applicable only if second parameter is true.)
     * @return array or string (depending on second parameter. Returns False if invalid hex color value)
     */
    private static function hex_to_rgb($hexstr, $returnasstring = false, $seperator = ',') {
        //$hexStr = preg_replace("/[^0-9A-Fa-f]/", '', $hexStr); // Gets a proper hex string
        $rgbarray = array();
        if (strlen($hexstr) == 6) { //If a proper hex code, convert using bitwise operation. No overhead... faster
            $colorval = hexdec($hexstr);
            $rgbarray['red'] = 0xFF & ($colorval >> 0x10);
            $rgbarray['green'] = 0xFF & ($colorval >> 0x8);
            $rgbarray['blue'] = 0xFF & $colorval;
        } else if (strlen($hexstr) == 3) { //if shorthand notation, need some string manipulations
            $rgbarray['red'] = hexdec(str_repeat(substr($hexstr, 0, 1), 2));
            $rgbarray['green'] = hexdec(str_repeat(substr($hexstr, 1, 1), 2));
            $rgbarray['blue'] = hexdec(str_repeat(substr($hexstr, 2, 1), 2));
        } else {
            return false; //Invalid hex color code
        }
        return $returnasstring ? implode($seperator, $rgbarray) : $rgbarray; // returns the rgb string or the associative array
    }


    /**
     * Returns string of css style definitions for classes requested
     * @param $classattr class attribute to search against
     * @param $styletags array style tags to search against
     */
    public static function get_styles($classattr, $styletags) {
        $css = '';//css string from the document style tags
        $styles = '';//contents of relevant class definitions
        $sarray = array();//css in array from doc style tags
        $classarray = explode(' ', $classattr);

        foreach ($styletags as $styletag) {
            //Add all inline style declarations into one string
            $css .= $styletag->textContent;
        }
        $css = trim($css, "\t\n\r");
        //Get styles and definitions added into array
        $csstks = strtok($css, '{}');
        while ($csstks !== false) {
            $sarray[] = $csstks;
            $csstks = strtok("{}");
        }

        //loop through class att and see if we have the class definition
        foreach ($classarray as $class) {
            $class = '.' . $class;
            //Find reference to class in array of styles
            for ($i = 0, $len = count($sarray); $i < $len; $i++) {
                if (strpos($sarray[$i], $class) !== false) {
                    if ($i != $len -1) {//make sure not undefined offset
                        $styles .= $sarray[$i + 1];
                    }
                }
            }
        }
        return $styles;
    }

    /**
     * Takes an image tag and returns the inline rtf for the file
     * Includes width and height of image and image type (e.g. png)
     * @param array $imagenodes image node sent fom xslt
     */
    public static function insert_image($imagenodes) {
        $imagenode = $imagenodes[0];
        $imagesrc = $imagenode->getAttribute('src');
        $images = self::$images;
        $converted = false;

        if (!empty($images) && !empty($images[urldecode($imagesrc)])) {
            $fh = self::$images[urldecode($imagesrc)];
        } else {
            //get the image (if we can't get access then send nothing)
            $fh = @download_file_content($imagesrc);
        }

        if (!$fh) {
            return '';
        } else {
            if (strpos($imagesrc, '.gif')) {
                // Try and convert gif into a png as no gif support in rtf.
                if (extension_loaded('gd')) {
                    if ($img = @imagecreatefromstring($fh)) {
                        ob_start();
                        if (imagepng($img)) {
                            $fh = ob_get_contents();
                            $converted = true;
                        }
                        ob_end_clean();
                    }
                }
            }
            //Work out image hex
            $pichex = '';
            for ($i = 0, $len = strlen($fh); $i < $len; $i++) {
                $hex = dechex((float) ord($fh[$i]));
                if (strlen($hex) == 1) {
                    $hex = '0' . $hex;
                }
                $pichex .= $hex;
            }
        }

        //Get width and height of image
        $imageinfo = false;
        //use GD lib if enabled (no extra download)

        if (extension_loaded('gd')) {
            if ($img = @imagecreatefromstring($fh)) {
                $imageinfo = array();
                $imageinfo[0] = imagesx($img);
                $imageinfo[1] = imagesy($img);
            }
        }
        if (!$imageinfo) {
            //GD not enabled or failed
            $imageinfo = @getimagesize($imagesrc);
        }
        if (!$imageinfo) {
            //All methods failed use defaults
            $imageinfo = array();
        }

        $width = 40;//default
        $height = 40;//default
        if ($imagenode->hasAttribute('width')) {
            $width = $imagenode->getAttribute('width');
        } else {
            if (isset($imageinfo[0])) {
                $width = $imageinfo[0];
            }
        }
        if ($imagenode->hasAttribute('height')) {
            $height = $imagenode->getAttribute('height');
        } else {
            if (isset($imageinfo[1])) {
                $height = $imageinfo[1];
            }
        }

        //convert width/height PIXELS to POINTS
        $width = round($width * 14.988078);
        $height = round($height * 14.988078);

        //work out format
        $typestring = '\jpegblip ';
        if ((isset($imageinfo[2]) && $imageinfo[2] == 3) || strpos($imagesrc, '.png')
                || (strpos($imagesrc, '.gif') && $converted)) {
            $typestring = '\pngblip ';
        }

        //return everything
        $returnst = '\picwgoal' . $width;
        $returnst .= '\pichgoal' . $height;
        $returnst .= $typestring;
        $returnst .= $pichex;
        return $returnst;
    }

    /**
     * Conversion from original at http://www.kartmann.org/freeware/XHTML2RTF/
     * @param array $objxmlodes
     * @param text $strtext
     * @param Int $intmynormalizespaces
     */
    public static function rtfencode($objxmlodes, $strtext, $intmynormalizespaces) {
        // Encode text, character by character

        if ($intmynormalizespaces == 1) {
            // Replace multiple spaces by one single space
            $strtext = preg_replace('/ +/', ' ', $strtext);
        }

        $blnappendparagraphbreak = false;

        // Build an array of characters
        $arrchars = str_split($strtext);
        for ($intchar = 0, $len = count($arrchars); $intchar < $len; $intchar++) {
            $strchar = $arrchars[$intchar];
            switch ($strchar) {
                case "\\":
                case "{":
                case "}":
                    // Encode backslashes, left curly bracket, right curly bracket (prefix with a backslash)
                    // Check we don't double encode character control words we want
                    if (($strchar == "\\" && isset($arrchars[$intchar + 1]))
                            && $arrchars[$intchar + 1] == "u"
                        ) {
                        break;
                    };
                    $arrchars[$intchar] = "\\" . $strchar;
                    break;

                case "&#160;":
                    // Encode non-breacking space (backslash+tilda)
                    $arrchars[$intchar] = "\\~";
                    break;

                case "\n":
                    if ($intmynormalizespaces == 2) {
                        // Preformatted mode - use \line for all EOL characters
                        $arrchars[$intchar] = "\\line ";
                        // Check if next node is a paragraph - if yes, we will use a paragraph break INSTEAD of line break
                        // Edit by sam: Although this is not documented,
                        // simplexml_import_dom does not work for text nodes.
                        if ($objxmlodes != null && count($objxmlodes) != 0 &&
                                !($objxmlodes[0] instanceof DOMText)) {
                            $objxmlcontext = $objxmlodes[0];
                            $s = simplexml_import_dom($objxmlcontext);
                            $objnextnode = $s->xpath('following-sibling::node()[position() = 1]');
                            if (count($objnextnode) > 0) {
                                if ($objnextnode->getName() == "p") {
                                    $blnappendparagraphbreak = true;
                                }
                            }
                        }
                    }
                    break;

                default:
                    $intcharcode = ord(substr($strchar, 0, 1));
                    if ($intcharcode > 255) {
                        // Non-ascii: encode as UNICODE (\u)
                        $arrchars[$intchar] = "{\u" . $intcharcode . "  }";//TODO check this works!
                    } else {
                        // TODO Handle control characters (ASCII code lesser than 32 - TAB, EOL, etc...)
                        // No encoding
                    }
                    break;

            }
        }

        // Convert back array to string
        $strrtfencoded = implode("", $arrchars);

        if ($blnappendparagraphbreak) {
            // Append a paragraph break - next node is a p tag, but we are not inside a p tag (bad!)
            $strrtfencoded .= "\\par ";
        }

        return $strrtfencoded;
    }

    /**
     * Conversion from original at http://www.kartmann.org/freeware/XHTML2RTF/
     * @param $objxmlnodes array
     * @param $tablewidth Int
     */
    public static function tablecellwidthfill($objxmlnodes, $tablewidth) {
        $strtext = "";
        if ($objxmlnodes != null && count($objxmlnodes) != 0) {
            $objrownodes = $objxmlnodes[0]->getElementsByTagName('tr');
            $objrownode;
            $objcolnodes;
            self::$arrmaxlen = Array();
            self::$arrtotlen = Array();
            $maxwordlen;
            for ($i = 0, $len = $objrownodes->length; $i < $len; $i++) {
                $objrownode = $objrownodes->item($i);
                $objcolnodes = $objrownode->getElementsByTagName('*');
                if (is_a($objcolnodes, 'DOMNodeList')) {
                    $counter = 0;
                    for ($j = 0, $len2 = $objcolnodes->length; $j < $len2; $j++) {
                        if ($objcolnodes->item($j)->nodeName == 'td' || $objcolnodes->item($j)->nodeName == 'th') {
                            $arrwords = preg_split('/[\s+]/', $objcolnodes->item($j)->textContent);
                            $maxwordlen = 0;
                            if (isset(self::$arrtotlen[$counter])) {
                                if (self::$arrtotlen[$counter] < strlen($objcolnodes->item($j)->textContent)) {
                                    self::$arrtotlen[$counter] = strlen($objcolnodes->item($j)->textContent);
                                }
                            } else {
                                self::$arrtotlen[$counter] = strlen($objcolnodes->item($j)->textContent);
                            }
                            for ($iword = 0, $arrwordslen = count($arrwords); $iword < $arrwordslen; $iword++) {
                                if (strlen($arrwords[$iword]) > $maxwordlen) {
                                    $maxwordlen = strlen($arrwords[$iword]);
                                }
                            }
                            if (isset(self::$arrmaxlen[$counter])) {
                                self::$arrmaxlen[$counter] = self::$arrmaxlen[$counter] > $maxwordlen ?
                                self::$arrmaxlen[$counter] : ($maxwordlen+1);
                            } else {
                                self::$arrmaxlen[$counter] = $maxwordlen + 1;
                            }
                            $counter++;
                        }
                    }
                }
            }
            if ($tablewidth > 0 ) {
                $totalwidthtot = 0;
                $totalwidthmax = 0;

                for ($i = 0, $len = count(self::$arrmaxlen); $i < $len; $i++) {
                    $totalwidthtot += (self::$arrtotlen[$i]*self::$multiplywidth + (2*self::$marginwidth));
                    $totalwidthmax += (self::$arrmaxlen[$i]*self::$multiplywidth + (2*self::$marginwidth));
                }

                $tablewidthtot = $tablewidth;
                $tablewidthmax = $tablewidth;
                $midwidthtot = $tablewidth / count(self::$arrtotlen);
                $midwidthmax = $tablewidth / count(self::$arrmaxlen);
                $midaddtot = ($tablewidth - $totalwidthtot) / count(self::$arrtotlen);
                $midaddmax = ($tablewidth - $totalwidthmax) / count(self::$arrmaxlen);

                //lines below were commented
                /*$strtext .= "totalWidthTot = " . $totalwidthtot . " \n";
                $strtext .= "totalWidthMax = " . $totalwidthmax . " \n";
                $strtext .= "midAddTot = " . $midaddtot . " \n";
                $strtext .= "midAddMax = " . $midaddmax . " \n";*/

                for ($i = 0, $len = count(self::$arrmaxlen); $i < $len; $i++) {
                    self::$arrmaxlen[$i] =
                    (self::$arrmaxlen[$i]*self::$multiplywidth + (2*self::$marginwidth))
                    *$tablewidth/$totalwidthtot/self::$multiplywidth;
                }
            }
        }
        return $strtext;
    }

    /**
     * Conversion from original at http://www.kartmann.org/freeware/XHTML2RTF/
     * @param Int $icolumn
     * @param Int $bsum
     * @param Int $fontsize
     */
    public static function gettablecolumnwidth($icolumn, $bsum, $fontsize) {
        $multiplywidth = $fontsize*8;
        $sum = 0;
        $icolumn = $icolumn-1;
        if ($icolumn < count(self::$arrmaxlen) && $icolumn >= 0) {
            if ( $bsum == 0 ) {
                return ((2*self::$marginwidth) +  self::$arrmaxlen[$icolumn]*$multiplywidth);
            } else if ( $bsum == 1 ) {
                return ((2*self::$marginwidth) +  self::$arrtotlen[$icolumn]*$multiplywidth);
            } else if ( $bsum == 2 ) {
                for ($i = 0; $i <= $icolumn; $i++) {
                    $sum += ((2*self::$marginwidth) + self::$arrmaxlen[$i]*$multiplywidth);
                }
                return $sum;
            } else if ( $bsum == 3 ) {
                for ($i = 0; $i <= $icolumn; $i++) {
                    $sum += ((2*self::$marginwidth) + self::$arrtotlen[$i]*$multiplywidth);
                }
                return $sum;
            }
        }
        return 0;
    }
}
