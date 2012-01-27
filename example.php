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


/**
 * Test of a html to rtf conversion
 *
 * @package    local
 * @subpackage html2rtf
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/html2rtf.php');

$html = <<<DOC
<html>
<head>
<title>doc title</title>

<style>
.boldy{font-weight:bold}
</style>
<style>
.italic{font-style:italic;text-decoration:underline}
.dotted{border-bottom: dotted}
</style>
</head>
<body>
<h1>H1 Text</h1>
<h2>standard formatting tests</h2>
<p>paragraph</p>
<p>
    paragraph of formatted text: <b>bold tag</b>,
    <strong>strong tag</strong>,
    <span class="boldy">css styled bold span</span>
    <span class="boldy" style="text-decoration:underline">Span with bold class and underline style</span>
</p>
<p>
    paragraph of formatted text: <u>u tag</u>
    <em>em tag</em>
    <span class="italic">css styled span</span>
</p>
<p>
<span class="dotted">CSS style dotted underline</span> <span style="border-bottom:dashed">Inline styled dashed</span>
</p>
<p><a href="http://www.open.ac.uk">hyperlink</a><p>
<p><a href="http:www.wikipedia.org"><span style="text-decoration:none">Styled link using span</span></a></p>
<!-- comment shouldn't show -->
<div>div tag<p>in div</p></div>
<p>ordered list:<ol><li>list item</li><li>list item2</li></ol></p>
<p>unordered list:<ul><li>list item</li><li>list item2</li></ul></p>
<p>sub lists:<ul><li>has ordered:<ol><li>1</li><li>2</li></li></ul></p>
<h2>Funny characters</h2>
<p>&,é,",',&nbsp;,&egrave;</p>
<p>&#36; &#1268; \u345  &#345; &#19971; - \u19971 .Ӵ : hex &#x04F4;</p>
<p><span lang="ja-Hani" xml:lang="ja-Hani">東京</span></p>
<p>hard-coded&#160; nbsp</p>
<h2>Simple table (as per documentation)</h2>
<p>
<table width="50%">
<tr>
<td align="right">Date</td>
<td align="left"><b>28 September 2005</b></td>
</tr>
<tr>
<td align="right">Username</td>
<td align="left"><b>Anna Maria X</b></td>
</tr>
<tr>
<td align="right">Password</td>
<td align="left"><b>abracadabra ABRACADABRA</b></td>
</tr>
</table>
</p>
<div></div>
<h2>Image</h2>
<p>Tiger, grrr
<img
 src="http://upload.wikimedia.org/wikipedia/commons/thumb/a/a4/Tiger_in_the_water.jpg/220px-Tiger_in_the_water.jpg"
  title="A cat"/></p>
<h2>Image with width and height specified</h2>
<p><img src="http://www.open.ac.uk/ouhomepics/ouhome89.jpg" width="80" height="80"/></p>
<h2>USER INPUT FROM TINYMCE</h2>
<p>Hello, this text has been entered in tiny mce.</p>
<p><strong>Bold </strong><em>italic </em><span style="text-decoration: underline;">underline</span></p>
<p>Coloured <span style="color: #00BFFF;">text </span>and <span style="background-color: #ffff00;">highlighted</span></p>
<p><span style="background-color: #ffffff;">Table</span></p>
<table style="height: 50px; width: 50px;" border="0">
<tbody>
<tr>
<td>1</td>
<td>2</td>
</tr>
<tr>
<td>3</td>
<td>4</td>
</tr>
</tbody>
</table>
<p><span style="background-color: #ffffff;"><br /></span></p>
<h2>'Proper' Table</h2>
<p>
<table width="100%" border="0" cellspacing="0" cellpadding="0" summary="summary">
  <caption align="top">
    caption
  </caption>
  <tr>
    <th scope="col">1</th>
    <th scope="col">2</th>
    <th scope="col">3</th>
  </tr>
  <tr>
    <th scope="row">4</th>
    <td>5</td>
    <td>6</td>
  </tr>
</table>
</p>
<h2>Form elements</h2>
<p>
<input type="checkbox" id="check" checked='checked'/><label for="check">A checkbox</label>
</p>
</body>
</html>
DOC;

require_login();

$rtf = html2rtf::convert($html);

send_file($rtf, 'example.rtf', 1, 0, true);
