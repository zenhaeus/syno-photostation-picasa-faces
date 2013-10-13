<?php
/**
 *	Picasa XMP- Faces to Photo Station database and XMP.MP
 *  Copyright (C) 2013  Johannes "DerOetzi" Ott <DerOetzi@gmail.com>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

define('SYNO_PHOTO_EXIV2_PROG', '/usr/syno/bin/exiv2');
define('SYNO_PHOTO_DSM_USER_PROG', '/usr/syno/bin/synophoto_dsm_user');
define('SYNO_HOMES_PATH', '/var/services/homes/');

define('SYNO_PHOTO_USER_DB', '.SYNOPPSDB');
define('SYNO_PICASA_DB_STMT_UPDATE_MERGE', 0);
define('SYNO_PICASA_DB_STMT_SELECT_PHOTOS', 1);
define('SYNO_PICASA_DB_STMT_INSERT_LABEL', 2);
define('SYNO_PICASA_DB_STMT_INSERT_TAG', 3);
define('SYNO_PICASA_DB_STMT_SELECT_LABELS', 4);
define('SYNO_PICASA_DB_STMT_INSERT_MERGE', 5);
define('SYNO_PICASA_DB_STMT_SELECT_MERGE', 6);

echo <<< GNU_LICENSE

Picasa XMP- Faces to Photo Station database and XMP.MP

Copyright (C) 2013  Johannes "DerOetzi" Ott <http://DerOetzi@gmail.com>

This program comes with ABSOLUTELY NO WARRANTY;
This is free software, and you are welcome to redistribute it
under certain conditions;


GNU_LICENSE;

echo "Starting process...\n";

$arPSUserlist = array('COMMON');

@exec(SYNO_PHOTO_DSM_USER_PROG." --enum", $sDsmUserCount);
@exec(SYNO_PHOTO_DSM_USER_PROG." --enum 0 ".intval($sDsmUserCount[0]), $arDsmUserlist);
foreach($arDsmUserlist as &$sDsmUser) {
	$arDsmUser = explode(',', $sDsmUser);
	if (count($arDsmUser) != 6) {
		continue;
	}

	$sDsmUsername = $arDsmUser[1];
	if (file_exists(SYNO_HOMES_PATH . $sDsmUsername . '/photo/' . SYNO_PHOTO_USER_DB)) {
		$arPSUserlist[] = $sDsmUsername;
	}

}

foreach ($arPSUserlist as $sUsername) {

	if ($sUsername == 'COMMON') {
		$sUserPath = '';
	} else {
		$sUserPath = SYNO_HOMES_PATH . $sUsername . '/photo/';
	}

	echo("\nUsername: " . $sUsername . "\n");

	try {
		$oDBH = new DatabaseHandler($sUserPath);
		$oDBH->prepare();
	} catch(RuntimeException $e) {
		echo ($e->getMessage() . "\n");
		continue;
	}

	$arPhotos = $oDBH->getPhotos();

	foreach($arPhotos as $arPhoto) {
		echo($arPhoto['path'] . ': ');
		
		$oImage = new PSImage($sUserPath, $arPhoto);
		$oImage->prepare();
	
		if (!$oImage->isPicasaXMP()) {
			echo("No Picasa face information\n");
			$oDBH->setPhotoMerged($oImage->getIId());
			continue;
		}

		if ($oImage->isMicrosoftXMP()) {
			echo("Already have Microsoft Xmp.Data (Try to reindex image manual!)\n");
			$oDBH->setPhotoMerged($oImage->getIId());
			continue;
		}

		if (!$oImage->isImageResolutionLikeInTag()) {
			echo("Resolution changed since tagging with picasa\n");
			$oDBH->setPhotoMerged($oImage->getIId());
			continue;
		}

		$oImage->writeXMPMPTags();
		$oDBH->writeDatabaseTags($oImage);

		$oDBH->setPhotoMerged($oImage->getIId());
		echo("updated\n");
	}

}

echo "\nFinished process!\n\n";

class DatabaseHandler {

	private $bUserDatabase;

	private $sUserPath;

	private $oDBH;

	private $arStmts = array();

	private $arLabels;
	
	private $config_id = 0;

	public function __construct($sUserPath) {
		$this->bUserDatabase = ($sUserPath != '');
		$this->sUserPath = $sUserPath;
	}

	public function prepare() {
		try {
			if ($this->bUserDatabase) {
				$this->oDBH = new PDO('sqlite:' . $this->sUserPath . SYNO_PHOTO_USER_DB);
				$this->oDBH->exec('PRAGMA case_sensitive_like=1');
				$this->oDBH->exec('PRAGMA foreign_keys=ON');
			} else {
				$this->oDBH = new PDO('pgsql:dbname=photo', 'admin', '', array(PDO::ATTR_PERSISTENT => false, PDO::ATTR_EMULATE_PREPARES => true));
			}
		} catch(\PDOException $e) {
			throw new \RuntimeException('No database!', 0);
		}

		$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_LABEL] = $this->oDBH->prepare('INSERT INTO photo_label (name, category) VALUES (?, 0)');

		if ($this->bUserDatabase) {
			$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_TAG]
			= $this->oDBH->prepare('INSERT INTO photo_image_label (image_id, label_id, status, info_new) VALUES (?, ?, \'t\', ?)');
		} else {
			$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_TAG]
			= $this->oDBH->prepare('INSERT INTO photo_image_label (image_id, label_id, status, info_new) VALUES (?, ?, true, ?)');
		}

		$this->arStmts[SYNO_PICASA_DB_STMT_SELECT_PHOTOS]
			= $this->oDBH->prepare("SELECT id, path, resolutionx, resolutiony FROM photo_image WHERE id>? ORDER BY id" );

		$this->arStmts[SYNO_PICASA_DB_STMT_SELECT_LABELS] =	$this->oDBH->prepare('SELECT id, name FROM photo_label');

		$this->arStmts[SYNO_PICASA_DB_STMT_SELECT_MERGE] 
			= $this->oDBH->prepare("SELECT config_id, config_value FROM photo_config WHERE module_name='photo' AND config_key='picasa_merge'");
		
		$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_MERGE] 
			= $this->oDBH->prepare("INSERT INTO photo_config (module_name, config_key, config_value) VALUES ('photo', 'picasa_merge', ?)");
		
		$this->arStmts[SYNO_PICASA_DB_STMT_UPDATE_MERGE]
			= $this->oDBH->prepare("UPDATE photo_config SET config_value=? WHERE config_id=?");
	}

	public function getPhotos() {
		$this->arStmts[SYNO_PICASA_DB_STMT_SELECT_MERGE]->execute(array());
		if (($row = $this->arStmts[SYNO_PICASA_DB_STMT_SELECT_MERGE]->fetch(PDO::FETCH_ASSOC)) !== false) {
			$lastImageId = $row['config_value'];
			$this->config_id = $row['config_id'];
		} else {
			$lastImageId = 0;
		}
		
		$this->arStmts[SYNO_PICASA_DB_STMT_SELECT_PHOTOS]->execute(array($lastImageId));
		return $this->arStmts[SYNO_PICASA_DB_STMT_SELECT_PHOTOS]->fetchAll(PDO::FETCH_ASSOC);
	}

	public function setPhotoMerged($iId) {
		if ($this->config_id == 0) {
			$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_MERGE]->execute(array($iId));
			if ($this->bUserDatabase) {
				$this->config_id = $this->oDBH->lastInsertId();
			} else {
				$this->config_id = $this->oDBH->lastInsertId('photo_config_config_id_seq');
			}
		} else {
			$this->arStmts[SYNO_PICASA_DB_STMT_UPDATE_MERGE]->execute(array($iId, $this->config_id));
		}
	}

	public function writeDatabaseTags(PSImage $oImage) {
		if (!isset($this->arLabels)) {
			$this->prepareLabels();
		}

		foreach($oImage->getArTags() as $sName=>$arRectangle) {
			if (!key_exists($sName, $this->arLabels)) {
				$this->insertLabel($sName);
			}

			$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_TAG]->execute(array($oImage->getIId(), $this->arLabels[$sName], json_encode($arRectangle)));
		}
	}

	private function prepareLabels() {
		$this->arStmts[SYNO_PICASA_DB_STMT_SELECT_LABELS]->execute();
		$this->arLabels = array();
		foreach ($this->arStmts[SYNO_PICASA_DB_STMT_SELECT_LABELS]->fetchAll(PDO::FETCH_ASSOC) as $arTag) {
			if (empty($arTag['name'])) {
				continue;
			}
			$this->arLabels[$arTag['name']] = $arTag['id'];
		}
	}

	private function insertLabel($sName) {
		$this->arStmts[SYNO_PICASA_DB_STMT_INSERT_LABEL]->execute(array($sName));
		if ($this->bUserDatabase) {
			$this->arLabels[$sName] = $this->oDBH->lastInsertId();
		} else {
			$this->arLabels[$sName] = $this->oDBH->lastInsertId('photo_label_id_seq');
		}
	}
}

class PSImage {

	private $iId;

	private $sFilename;

	private $sEscapedFilename;

	private $arPhoto;

	private $arTags;

	private $arExifData = array();

	public function __construct($sUserPath, $arPhoto) {
		$this->iId = $arPhoto['id'];
		$this->sFilename = $sUserPath . $arPhoto['path'];
		$this->sEscapedFilename = escapeshellarg($this->sFilename);
		$this->arPhoto = $arPhoto;
	}

	public function prepare() {
		$cmd = SYNO_PHOTO_EXIV2_PROG . ' -Pkt ' . $this->sEscapedFilename;
		@exec($cmd, $arResultLines);

		foreach ($arResultLines as $sResultLine) {
			list($sKey, $sValue) = array_map('trim', explode(' ', $sResultLine, 2));
			$arKeys = explode ('/', $sKey);
			if ($arKeys[0] != 'Xmp.mwg-rs.Regions' && $arKeys[0] != 'Xmp.MP.RegionInfo') {
				continue;
			}
			$this->arExifData = $this->addRecursiveExifLine($this->arExifData, $arKeys, $sValue);
		}

		if ($this->isPicasaXMP()) {
			$this->prepareTaglist();
		}
	}

	private function addRecursiveExifLine($arData, $arKeys, $sValue) {
		$arKey = explode('[', $arKeys[0]);
		$sKey = $arKey[0];
		if (count($arKey) == 1) {
			$iIndex = -1;
		} else {
			$iIndex = intval(substr($arKey[1], 0, -1));
		}

		if (count($arKeys) == 1) {
			if ($sValue == 'type="Struct"' || $sValue == 'type="Bag"' || $sValue == 'type="Seq"') {
				if ($iIndex == -1) {
					$arData[$sKey] = array();
				} else {
					$arData[$sKey][$iIndex] = array();
				}
			} else {
				if ($iIndex == -1) {
					$arData[$sKey] = $sValue;
				} else {
					$arData[$sKey][$iIndex] = $sValue;
				}
			}
		} else {
			if ($iIndex == -1) {
				$arData[$sKey] = $this->addRecursiveExifLine($arData[$sKey], array_slice($arKeys, 1), $sValue);
			} else {
				$arData[$sKey][$iIndex] = $this->addRecursiveExifLine($arData[$sKey][$iIndex], array_slice($arKeys, 1), $sValue);
			}
		}

		return $arData;
	}

	public function isPicasaXMP() {
		return key_exists('Xmp.mwg-rs.Regions', $this->arExifData);
	}

	private function prepareTaglist() {

		foreach ($this->arExifData['Xmp.mwg-rs.Regions']['mwg-rs:RegionList'] as $arExifTag) {
			if ($arExifTag['mwg-rs:Type'] != 'Face') {
				continue;
			}

			$arArea = $arExifTag['mwg-rs:Area'];

			$x = $arArea['stArea:x'] - ($arArea['stArea:w'] / 2);
			$y = $arArea['stArea:y'] - ($arArea['stArea:h'] / 2);

			$this->arTags[$arExifTag['mwg-rs:Name']] = array('x'=>$x, 'y'=>$y, 'width'=>$arArea['stArea:w'], 'height'=>$arArea['stArea:h']);
		}

	}

	public function isMicrosoftXMP() {
		return key_exists('Xmp.MP.RegionInfo', $this->arExifData);
	}

	public function isImageResolutionLikeInTag() {
		$arTagDimension = $this->arExifData['Xmp.mwg-rs.Regions']['mwg-rs:AppliedToDimensions'];
		return $this->arPhoto['resolutionx'] == $arTagDimension['stDim:w'] && $this->arPhoto['resolutiony'] == $arTagDimension['stDim:h'];
	}

	public function writeXMPMPTags() {

		$sNameSpaceReg = '-M "reg MP http://ns.microsoft.com/photo/1.2/" '.
				'-M "reg MPRI http://ns.microsoft.com/photo/1.2/t/RegionInfo#" '.
				'-M "reg MPReg http://ns.microsoft.com/photo/1.2/t/Region#"';

		$cmd = sprintf('%s %s %s %s', SYNO_PHOTO_EXIV2_PROG, $sNameSpaceReg,
				'-M "set Xmp.MP.RegionInfo/MPRI:Regions XmpText type=Bag"',
				$this->sEscapedFilename);

		@exec($cmd);

		$iIndex = 1;

		foreach($this->arTags as $sTagName=>$arRectangle) {
			$sPersonDisplayName = str_replace('"', '\"', $sTagName);
			$sRectangle = $arRectangle['x'] . ', ' . $arRectangle['y'] . ', ' . $arRectangle['width'] . ', ' . $arRectangle['height'];

			$cmd = sprintf('%s %s %s %s %s', SYNO_PHOTO_EXIV2_PROG, $sNameSpaceReg,
					'-M "set Xmp.MP.RegionInfo/MPRI:Regions['.$iIndex.']/MPReg:Rectangle '.$sRectangle.'"',
					'-M "set Xmp.MP.RegionInfo/MPRI:Regions['.$iIndex.']/MPReg:PersonDisplayName ' . $sPersonDisplayName . '"',
					$this->sEscapedFilename);

			@exec($cmd);

			$iIndex++;
		}

	}

	public function getIId() {
		return $this->iId;
	}

	public function getArTags() {
		return $this->arTags;
	}

}

