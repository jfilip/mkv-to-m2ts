#!/usr/bin/env php
<?php

// This script is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This script is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this script.  If not, see <http://www.gnu.org/licenses/>.

/**
 * mkv-to-m2ts.php
 *
 * This is a script that can be used to convert an MKV video file containing an h.264 video stream and
 * a DTS or AC3 audio stream into an M2TS format. Specifically, this script takes MKVs and produces
 * video files which can be played on a Sony Playstation 3 with full multichannel audio support.
 *
 * Required command-line software packages / tools:
 * - ffmpeg     -- htttp://ffmpeg.org/
 * - mediainfo  -- http://mediainfo.sourceforge.net/en/
 * - mkvtoolnix -- http://www.bunkus.org/videotools/mkvtoolnix/
 *
 * @author    Justin Filip <jfilip@gmail.com>
 * @copyright 2012 and onwards Justin Filip http://jfilip.ca/
 * @link      https://github.com/jfilip/mkv-to-m2ts
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Display the arguments available for this program that are required and optinal for it's use
 *
 * @param array $argv The CLI arguments specified to execute this program
 */
function print_usage($argv) {
    echo $argv[0].' -i "input_filename" [-o"output_filename"] [-t"temp_dir"]'."\n\n";
    exit;
}

/**
 * Display an error message and halt execution
 *
 * @param string $err The error message to display
 */
function print_error($err) {
    die('ERROR: '.$err."\n");
}

/**
 * Fetch a value from a SimpleXMLElement object based on an xpath search query.
 *
 * @param SimpleXMLElement $element  A SimpleXMLElement object to perform a xpath() call on
 * @param string           $xpath    The xpath string to search for on the given element
 * @param string           $type     An optional type to cast the returned value as (string, int, float)
 * @param string           $errormsg An optional error message to display if the xpath fails
 */
function fetch_xpath_value($element, $xpath, $type = '', $errormsg = '') {
    if (!is_a($element, 'SimpleXMLElement')) {
        print_error('fetch_xpath_value: invalid $element parameter specified: '.get_class($element));
    }

    $result = $element->xpath($xpath);
    if (!is_array($result)) {
        print_error(!empty($errormsg) ? $errormsg : 'no results found for xpath: '.$xpath);
    }

    switch ($type) {
        case 'int':
            return (int)current($result);
            break;
        case 'string':
            return (string)current($result);
            break;
        case 'float':
            return (float)current($result);
            break;
        case 'array':
            return $result;
            break;
        default:
            return current($result);
            break;
    }
}

/**
 * Validate the input parameters and get our setup object started
 *
 * @param array $setup   A reference to an array containing script setup data
 * @param array $options An array containing the CLI arguments passed into this script
 */
function validate_parameters(&$setup, $options) {
    if (!file_exists($options['i'])) {
        print_error('invalid input file "'.$options['i'].'"');
    }

    $setup['file_in'] = $options['i'];

    // If an output dir/file was specified, validate that and setup the appropriate output file path and name
    if (isset($options['o'])) {
        // If a directory was specified, store the converted file there using the same filename with the .m2ts extension
        if (is_dir($options['o'])) {
            if ($options['o'][strlen($options['o']) - 1] != '/') {
                $options['o'] .= '/';
            }

            // Determine which file extension we're dealing with
            if (substr($setup['file_in'], -3) == '.ts') {
                $filein = basename($setup['file_in'], '.ts');
            } else if (substr($setup['file_in'], -4) == '.mkv') {
                $filein = basename($setup['file_in'], '.mkv');
            }
            $setup['file_out'] = $options['o'].$filein.'.m2ts';

        // If a full file path was specified, verify that the directory exists and the given file name does not
        } else {
            $pathinfo = pathinfo($options['o']);

            // Check if a malformed directory name was specified as input (output file must contain .m2ts extension)
            if (($pathinfo['basename'] == $pathinfo['filename']) && !is_dir($options['o'])) {
                print_error($pathinfo['dirname'].'" is not a valid directory');
            }
            if (file_exists($options['o'])) {
                print_error('cannot write output to "'.$options['o'].'"" as that file already exists');
            }
            if (substr($options['o'], -4) !== '.m2ts') {
                print_error('output filename must use the .m2ts extension');
            }

            $setup['file_out'] = $options['o'];
        }
    } else {
        // Just use the input filename with the .m2ts and write the new file in the same directory as the input file
        $pathinfo = pathinfo($setup['file_in']);

        $setup['file_out'] = $pathinfo['dirname'].'/'.basename($setup['file_in'], '.mkv').'.m2ts';
    }

    // If a temp directory was specified, validate that it is a directory
    if (isset($options['t'])) {
        if (!is_dir($options['t'])) {
            print_error('temp directory "'.$options['t'].'" is invalid');
        } else {
            $setup['temp_dir'] = $options['t'];
        }
    } else {
        // Use the current directory as the temporary storage
        $setup['temp_dir'] = getcwd();
    }

    if ($setup['temp_dir'][strlen($setup['temp_dir']) - 1] != '/') {
        $setup['temp_dir'] .= '/';
    }
}

/**
 * Check for required programs
 *
 * @param array $setup   A reference to an array containing script setup data
 */
function check_requirements(&$setup) {
    $setup['programs'] = array(
        'mediainfo'  => '',
        'ffmpeg'     => '',
        'mkvextract' => '',
        'dcadec'     => '',
        'aften'      => '',
        'tsMuxeR'    => '',
        'ts2es'      => 'optional',
        'faad'       => 'optional',
        'mkvmerge'   => 'optional'
    );

    foreach ($setup['programs'] as $prog => $path) {
        $output = array();
        exec('which '.$prog, $output, $return);
        // Only print an error if the program is required
        if ($return != 0 && $path != 'optional') {
            print_error('could not find path for executable "'.$prog.'"');
        } else {
            $setup['programs'][$prog] = $output[0];
        }
    }
}

/**
 * Check for a valid input file and setup some variables for the transcoding process
 *
 * @param array $setup   A reference to an array containing script setup data
 * @param array $options An array containing the CLI arguments passed into this script
 */
function validate_input(&$setup, $options) {
    // Get an XML document describing the input file's container and various A/V streams within
    exec($setup['programs']['mediainfo'].' --Output=XML "'.$setup['file_in'].'"', $output, $return);

    if ($return != 0) {
        print_error('executing command: "'.$setup['programs']['mediainfo'].' --Output=XML '.$setup['file_in'].'"');
    }

    $mediainfoxml = implode("\n", $output);
    $mediainfo    = new SimpleXMLElement($mediainfoxml);

    // Check the container format of the input file
    $container_format = fetch_xpath_value($mediainfo, 'File/track[@type="General"]/Format', 'string', 'no input container format specified');
    if (strtoupper($container_format) != 'MATROSKA' && strtoupper($container_format) != 'MPEG-TS') {
        print_error('invalid input container format: '.$container_format.' srttoupper() = '.strtoupper($container_format));
    }

    $setup['container_format'] = strtoupper($container_format);

    // If this is a TS container, make sure that we have the required program to process this file
    if ($setup['container_format'] == 'MPEG-TS' && $setup['programs']['ts2es'] == 'optional') {
        print_error('required program to repackage an MPEG-TS container is missing: ts2es');
    }

    // Check for a video stream
    $video_stream = fetch_xpath_value($mediainfo, 'File/track[@type="Video"]', '', 'no input video stream found');

    // Get the ID (required for mkvextract) for the video stream
    $video_id = fetch_xpath_value($video_stream, './ID', 'int', 'no video stream ID found');
    $setup['video_stream'] = $video_id - 1;

    // Check for a valid video stream in the input file
    $video_format = fetch_xpath_value($video_stream, './Codec_ID', 'string', 'no input video codec specified');

    if (($setup['container_format'] == 'MATROSKA' && (string)$video_format != 'V_MPEG4/ISO/AVC') ||
            $setup['container_format'] == 'MPEG-TS' && (int)$video_format != 27) {
        print_error('invalid input video codec: '.(string)$video_format);
    }

    // Check for the format level of the video stream
    $format_level = fetch_xpath_value($video_stream, './Format_profile', 'string', 'no video format level found');
    preg_match('/@L([1-9]\.[0-9])/', $format_level, $matches);
    if (!isset($matches[1])) {
        print_error('could not detect valid video format level');
    }
    $setup['video_format_level'] = (float)$matches[1];

    // Check for the FPS of the video stream
    $frame_rate = fetch_xpath_value($video_stream, './Frame_rate', 'string', 'no video format frame rate found');
    preg_match('/([1-9]+\.[0-9]+) fps/', $frame_rate, $matches);
    if (!isset($matches[1])) {
        print_error('could not detect valid video frame rate level');
    }
    $setup['video_fps'] = $matches[1];

    // Check for a valid audio stream in the input file
    $audio_streams = fetch_xpath_value($mediainfo, 'File/track[@type="Audio"]', 'array', 'no input audio streams specified');

    foreach ($audio_streams as $audio_stream) {
        if (isset($setup['audio_stream'])) {
            continue;
        }

        // Get the format for the current audio stream
        $audio_format = fetch_xpath_value($audio_stream, './Codec_ID', 'string', 'no input audio codec specified');

        // Prefer a DTS stream over an AC3 stream in the case where both are present
        if ($audio_format == 'A_DTS' || $audio_format == 'A_AC3' || $audio_format = 'A_AAC') {
            if ($setup['container_format'] == 'MPEG-TS' && $audio_format == 'A_AAC' && $setup['programs']['faad'] == 'optional') {
                print_error('required program to reencode AAC audio is missing: faad');
            }
            // Get the audio stream language and verify that it is English
            $language = fetch_xpath_value($audio_stream, './Language', 'string', 'no audio stream language found');

            // If an audio language is specified and it is not English, keep looking (assume English if no language specified)
            if (!empty($language) && strtoupper($language) != 'ENGLISH') {
                continue;
            }

            // Get the ID (required for mkvextract) for the current audio stream
            $setup['audio_stream'] = fetch_xpath_value($audio_stream, './ID', 'int', 'no audio stream ID found') - 1;
            $setup['audio_codec']  = $audio_format;

            // Get the audio stream channel count
            $audio_channels = fetch_xpath_value($audio_stream, './Channel_s_', 'string', 'no audio channel information found');

            // Newer versions of mediainfo are reporting this differently, it would appear
            if (empty($audio_channels)) {
                $audio_channels = fetch_xpath_value($audio_stream, './Channel_count', 'string', 'no audio channel information found');
            }

            preg_match('/([0-9]) CHANNELS/', strtoupper($audio_channels), $matches);
            if (!isset($matches[1])) {
                print_error('could not detect valid audio channel information');
            }
            $setup['audio_channels'] = $matches[1];

            // For DTS we need to gather more information for converting the audio into AC3 format
            if ($setup['audio_codec'] == 'A_DTS') {
                // Get the audio stream bitrate
                $audio_bitrate = fetch_xpath_value($audio_stream, './Bit_rate', 'string', 'no audio bitrate found');
                preg_match('/([0-9]+) KBPS/', strtoupper($audio_bitrate), $matches);
                if (!isset($matches[1])) {
                    print_error('could not detect valid audio bit rate');
                }
                $setup['audio_bitrate'] = $matches[1];
            }
        }
    }

    if (!isset($setup['audio_codec'])) {
        print_error('no valid audio video codecs found');
    }
}

/**
 * Cleanup any temporary files created during the media transcode / repackage process
 *
 * @param array $setup  An array containing script setup data
 * @param bool  $repack Optional flag to indicate whether to check for and delete a repackaged MKV file
 */
function cleanup_temp_files($setup, $repack = false) {
    echo 'Cleaning up temporary files ... ';

    unlink($setup['temp_dir'].'video.h264');
    if ($setup['audio_codec'] == 'A_DTS') {
        unlink($setup['temp_dir'].'audio.dts');
    }
    if ($setup['audio_codec'] == 'A_DTS' || $setup['audio_codec'] == 'A_AC3') {
        unlink($setup['temp_dir'].'audio.ac3');
    } else if ($setup['audio_codec'] == 'A_AAC') {
        unlink($setup['temp_dir'].'audio.aac');
    }
    unlink($setup['temp_dir'].'tsmuxer.meta');

    // If we have a repackaged MKV file, remove it now
    if ($repack && isset($setup['file_repack']) && file_exists($setup['file_repack'])) {
        unlink($setup['file_repack']);
    }

    echo 'done!'."\n";
}

/**
 * Rip apart the audio and video streams, re-building the original MKV container to pass through
 * the encoding process again.
 *
 * @param array $setup An array containing script setup data
 * @return bool True on success, False otherwise
 */
function container_rebuild($setup) {
    if ($setup['programs']['mkvextract'] == 'optional') {
        print_error('required program to repackage an MKV container are missing: mkvmerge');
        return false;
    }

    $setup['file_repack'] = str_replace('.mkv', '.TEMPORARY_FILE.mkv', $setup['file_in']);

    $execstr = $setup['programs']['mkvmerge'].' -o "'.$setup['file_repack'].'" --default-duration 0:'.$setup['video_fps'].'fps '.
               $setup['temp_dir'].'video.h264 ';

    switch ($setup['audio_codec']) {
        case 'A_DTS':
        case 'A_AC3':
            $execstr .= $setup['temp_dir'].'audio.ac3';
            break;

        case 'A_AAC':
            $execstr .= '--aac-is-sbr 0 '.$setup['temp_dir'].'audio.aac';
            break;
    }

    echo 'Building a new temporary MKV file ... ';
    exec($execstr, $output, $return);
    echo 'done!'."\n";

    if ($return != 0) {
        cleanup_temp_files($setup);
        // If the new, repackaged, file exists, we need to remove it as well.
        if (file_exists($setup['file_repack'])) {
            unlink($setup['file_repack']);
        }

        print_error('failure while executing mkvmerge'."\n\n".implode("\n", $output));
        exit;
    }

    // Remove existing temporary files as we are going to be extracting streams again from our new MKV
    cleanup_temp_files($setup);
    perform_transcode($setup);
}

/**
 * Perform the actual transcode process based on the variables setup from the input validation
 *
 * @param array $setup An array containing script setup data
 */
function perform_transcode($setup) {
    if (isset($setup['file_repack'])) {
        $infile = $setup['file_repack'];
    } else {
        $infile = $setup['file_in'];
    }

    // Determine which specific program we are using to extract elementary streams out of our container file
    if ($setup['container_format'] == 'MATROSKA') {
        $extractor = $setup['programs']['mkvextract'];
    } else if ($setup['container_format'] == 'MPEG-TS') {
        $extractor = $setup['programs']['ts2es'];
    }

    // Extract the video stream into a file
    echo 'Extracting video stream ... ';
    if ($setup['container_format'] == 'MATROSKA') {
        exec($extractor.' tracks "'.$infile.'" '.$setup['video_stream'].':'.$setup['temp_dir'].'video.h264', $output, $return);
    } else if ($setup['container_format'] == 'MPEG-TS') {
        exec($extractor.' -video "'.$infile.'" '.$setup['temp_dir'].'video.h264', $output, $return);
    }
    if ($return != 0) {
        print_error('failure while executing '.$extractor."\n\n".implode("\n", $output));
    }
    echo 'done!'."\n";

    // DTS audio must be converted to AC3 format
    if ($setup['audio_codec'] == 'A_DTS') {
        echo 'Extracting audio stream ... ';
        if ($setup['container_format'] == 'MATROSKA') {
            exec($extractor.' tracks "'.$infile.'" '.$setup['audio_stream'].':'.$setup['temp_dir'].'audio.dts', $output, $return);
        } else if ($setup['container_format'] == 'MPEG-TS') {
            exec($extractor.' -audio "'.$infile.'" '.$setup['temp_dir'].'audio.dts', $output, $return);
        }
        if ($return != 0) {
            print_error('failure while executing '.$extractor."\n\n".implode("\n", $output));
        }
        echo 'done!'."\n";

        echo 'Converting DTS audio stream to AC3 ... ';
        exec($setup['programs']['dcadec'].' -o wavall "'.$setup['temp_dir'].'audio.dts" | aften -b 640 -v 0 - "'.$setup['temp_dir'].'audio.ac3"', $output, $return);
        if ($return != 0) {
            print_error('failure while executing dcadec and aften'."\n\n".implode("\n", $output));
        }
        echo 'done!'."\n";
    } else if ($setup['audio_codec'] == 'A_AC3') {
        echo 'Extracting audio stream ... ';
        if ($setup['container_format'] == 'MATROSKA') {
            exec($extractor.' tracks "'.$infile.'" '.$setup['audio_stream'].':'.$setup['temp_dir'].'audio.ac3', $output, $return);
        } else if ($setup['container_format'] == 'MPEG-TS') {
            exec($extractor.' -audio "'.$infile.'" '.$setup['temp_dir'].'audio.ac3', $output, $return);
        }
        if ($return != 0) {
            print_error('failure while executing '.$extractor."\n\n".implode("\n", $output));
        }
        echo 'done!'."\n";
    } else if ($setup['audio_codec'] == 'A_AAC') {
        echo 'Extracting audio stream ... ';
        if ($setup['container_format'] == 'MATROSKA') {
            exec($extractor.' tracks "'.$infile.'" '.$setup['audio_stream'].':'.$setup['temp_dir'].'audio.aac', $output, $return);
        } else if ($setup['container_format'] == 'MPEG-TS') {
            exec($extractor.' -audio "'.$infile.'" '.$setup['temp_dir'].'audio.aac', $output, $return);
        }

        if ($return != 0) {
            print_error('failure while executing '.$extractor."\n\n".implode("\n", $output));
        }
        echo 'done!'."\n";

        if ($setup['container_format'] == 'MPEG-TS') {
            echo 'Converting AAC audio to AC3 ... ';
            exec($setup['programs']['faad'].' -b 2 -f 2 -q -w '.$setup['temp_dir'].'audio.aac | aften -v 1 -b 640 -raw_fmt s24_le -raw_sr '.
                    '48000 -raw_ch '.$setup['audio_channels'].' -chmap 2 - '.$setup['temp_dir'].'audio.ac3', $output, $return);

            if ($return != 0) {
                print_error('failure while converting AAC to AC3'."\n\n".implode("\n", $output));
            }
            $setup['audio_codec'] = 'A_AC3';
            echo 'done!'."\n";
        }
    }

    // Generate the meta information file for tsMuxeR
    if (!$fh = fopen($setup['temp_dir'].'tsmuxer.meta', 'w+')) {
        print_error('Cculd not open meta file for writing');
    }

    fwrite($fh, 'MUXOPT --no-pcr-on-video-pid --new-audio-pes --vbr --vbv-len=500'."\n");

    if ($setup['video_format_level'] == 4.1 || $setup['video_format_level'] > 5) {
        fwrite($fh, 'V_MPEG4/ISO/AVC, "'.$setup['temp_dir'].'video.h264", level=4.1, insertSEI, contSPS, lang=eng, fps='.$setup['video_fps']."\n");
    } else {
        fwrite($fh, 'V_MPEG4/ISO/AVC, "'.$setup['temp_dir'].'video.h264", insertSEI, contSPS, lang=eng, fps='.$setup['video_fps']."\n");
    }

    if ($setup['audio_codec'] == 'A_DTS' || $setup['audio_codec'] == 'A_AC3') {
        fwrite($fh, 'A_AC3, "'.$setup['temp_dir'].'audio.ac3"'."\n");
    } else if ($setup['audio_codec'] == 'A_AAC') {
        fwrite($fh, 'A_AAC, "'.$setup['temp_dir'].'audio.aac"'."\n");
    }
    fclose($fh);

    echo 'Packaging M2TS file ... ';
    exec($setup['programs']['tsMuxeR'].' '.$setup['temp_dir'].'tsmuxer.meta "'.$setup['file_out'].'"', $output, $return);
    if ($return != 0) {
        $lines = implode("\n", $output);

        // If there was an error detecting FPS when creating the M2TS container, let's try to rebuild the MKV container
        // NOTE: this only happens if we are not trying to assemble the M2TS file from a repakcaged MKV
        if (!isset($setup['file_repack']) && strpos($lines, 'Frame rate: not found') !== false &&
                strpos($lines, 'H.264 stream does not contain fps field') !== false) {

            echo 'failed! Attempting to repackage MKV file and try again.'."\n";
            container_rebuild($setup);
            exit;
        } else {
            print_error('failure while executing tsMuxeR'."\n\n".$lines);
        }
    }

    echo 'done!'."\n";

    cleanup_temp_files($setup, true);
}


/**
 * Main program exeuction below
 */


// Get CLI script arguments
if (!$options = getopt("i:o::t::")) {
    print_usage($argv);
}

$setup = array();

validate_parameters($setup, $options);
check_requirements($setup);
validate_input($setup, $options);
perform_transcode($setup);
