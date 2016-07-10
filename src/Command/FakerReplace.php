<?php
namespace Testenv\Command;

use Faker\Factory as FakerFactory;
use Testenv\Config;
use Testenv\Database;
use Testenv\FakerProvider\HomePhone;
use Testenv\FakerProvider\MobilePhone;
use Testenv\FakerProvider\Person;
use Testenv\FakerProvider\ValidAddress;
use Testenv\Util;

/**
 * Class FakerReplace
 * @package Testenv\Command
 */
class FakerReplace extends BaseCommand {

  /**
   * @var FakerReplace $instance Command instance
   */
  protected static $instance;

  /**
   * @var \Faker\Generator $faker
   * For documentation, see https://github.com/fzaninotto/Faker
   */
  private $faker;

  /**
   * @var array $count Internal counter to display progress
   */
  private $count = [];

  /**
   * FakerData constructor. We added custom data providers to Faker for generating gender data with matching M/F
   * first names, correct Dutch phone numbers, and random postal addresses in The Netherlands that are
   * actually valid (ie postal code / city / street address match).
   */
  public function __construct() {

    $this->faker = $faker = FakerFactory::create(Config::FAKER_LOCALE);
    $faker->addProvider(new Person($faker));
    $faker->addProvider(new ValidAddress($faker));
    $faker->addProvider(new HomePhone($faker));
    $faker->addProvider(new MobilePhone($faker));
  }

  /**
   * Generate fake sample data for Drupal and CiviCRM using Faker.
   * This class will replace all existing sensitive contact data with fake data. It supports the most important database tables and fields, but may not replace or erase *all* sensitive data. Please review your installation manually after running this script.
   * WORKS LOCALLY: must be run from the NEW environment! FinishCopy can take care of this automatically if called from CreateNew.
   * @return mixed Result
   */
  public function run() {

    Util::log("TESTENV: Trying to replace all contact data with fake data for this environment (" . DRUPAL_ROOT . ")...", 'ok');

    // Version 1.1: use database queries . Using the CiviCRM API, this turns out to take days for 150,000 contacts. Instead of performing about 1,000,000 database queries one at a time, we'll simply generate an SQL file here instead (that can even be reused).
    $fakefile = Util::getTempDir() . DIRECTORY_SEPARATOR . 'sptestenv_faker_' . time() . '.sql';
    $ffp = fopen($fakefile, 'w');

    if(!$ffp) {
      Util::log('Could not open file for writing (' . $fakefile . ').', 'error');
      return false;
    }

    // Custom fields and relevant data
    civicrm_initialize();
    $currentMembershipStatuses = \CRM_Member_BAO_MembershipStatus::getMembershipStatusCurrent();

    // Start dumpfile
    fputs($ffp, "/* Database randomization SQL generated by Levity\\Testenv\\Command\\FakerReplace class at " . date('Y-m-d H:i:s') . ". */\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET AUTOCOMMIT = 0;\n\n");

    // Get all contacts, except for a few users that we won't modify
    $contact = \CRM_Core_DAO::executeQuery('SELECT id, is_deceased FROM civicrm_contact WHERE
                id NOT IN (' . Config::CIVI_KEEP_CONTACTS . ') AND
                (contact_sub_type IS NULL OR contact_sub_type NOT IN (' . Config::CIVI_KEEP_CONTACT_SUBTYPES . '))');
    $cids = [];

    // Walk contacts and add queries to update contacts table
    while ($contact->fetch()) {

      // Clear previous person's data and generate new faker data to add to this contact
      // (Removing tussenvoegsels here -> the Faker last name collection contains them too)
      $this->faker->clearPerson();
      $cquery = "UPDATE civicrm_contact SET
                   first_name = '" . $this->escapeString($this->faker->firstName) . "',
                   middle_name = '',
                   last_name = '" . $this->escapeString($this->faker->lastName) . "',
                   display_name = '" . $this->escapeString($this->faker->name) . "',
                   sort_name = '" . $this->escapeString($this->faker->sortName) . "',
                   gender_id = '" . (int) $this->faker->gender . "',
                   birth_date = '" . $this->escapeString($this->faker->dateTimeBetween('-80 years', '-14 years')->format('Y-m-d')) . "',
                   deceased_date = " . (!empty($contact->deceased_date) ? $this->escapeString($this->faker->dateTimeBetween('-10 years', 'now')->format('Y-m-d')) : 'NULL') . ",
                   email_greeting_display = '" . $this->escapeString($this->faker->contactGreeting) . "',
                   postal_greeting_display = '" . $this->escapeString($this->faker->contactGreeting) . "',
                   addressee_display = '" . $this->escapeString($this->faker->contactAddressee) . "'
                  WHERE id = " . (int) $contact->id;
      fputs($ffp, $cquery . ";\n");

      // Update initials (SP specific)
      $initials = $this->escapeString($this->faker->initials);
      if (!empty($initials)) {
        $inquery = "INSERT INTO civicrm_value_migratie_1 (entity_id, voorletters_1) VALUES ({$contact->id}, '{$initials}') ON DUPLICATE KEY UPDATE voorletters_1 = '{$initials}'";
        fputs($ffp, $inquery . ";\n");
      }

      $this->echoCount('contact');
    }

    // Update all Dutch addresses to a random address within the same postal code (!).
    // I wanted to do this with an UPDATE SELECT query but couldn't get it to work yet,
    // and for now the performance of this approach seems acceptable
    /** @var \DB_DataObject $addr Addresses */
    $addr = \CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_address WHERE
      country_id = 1152 AND contact_id NOT IN (' . Config::CIVI_KEEP_CONTACTS . ')');
    while ($addr->fetch()) {

      // Get a random valid address within the same postal code
      $this->faker->clearAddress();
      $this->faker->initializeAddress($addr->postal_code, $addr->street_number);

      $aquery = "UPDATE civicrm_address SET
               street_address = '" . $this->escapeString($this->faker->fullAddress) . "',
               street_name = '" . $this->escapeString($this->faker->streetName) . "',
               street_number = '" . $this->escapeString($this->faker->streetNumber) . "',
               street_unit = '" . $this->escapeString($this->faker->streetUnit) . "',
               city = '" . $this->escapeString($this->faker->city) . "',
               postal_code = '" . $this->escapeString($this->faker->postcode) . "',
               geo_code_1 = '" . $this->escapeString($this->faker->latitude) . "',
               geo_code_2 = '" . $this->escapeString($this->faker->longitude) . "',
               manual_geo_code = 1
              WHERE id = " . (int) $addr->id;

      fputs($ffp, $aquery . ";\n");
      $this->echoCount('address');
    }

    // Add query to remove all foreign addresses for now
    fputs($ffp, "DELETE FROM civicrm_address WHERE country_id != 1152;\n");
    // Remove all past and future addresses (SP specific)
    fputs($ffp, "TRUNCATE TABLE civicrm_value_futureaddress;\n");
    fputs($ffp, "TRUNCATE TABLE civicrm_value_address_history;\n");

    /*
    This query tries to get new random address data along with the current address -
    but performance isn't that much better than the code above. I haven't found an UPDATE SELECT query that works properly yet.
    SELECT addr.id AS addr_id,
      addr.street_address AS old_street_address, addr.city AS old_city,
      addr.postal_code AS old_postal_code, pcdb.id AS pcdb_id,
      ROUND((RAND() * (pcdb.huisnummer_tot - pcdb.huisnummer_van)) + pcdb.huisnummer_van) AS new_huisnummer,
      CONCAT(pcdb.postcode_nr,' ',pcdb.postcode_letter) AS new_postal_code,
      pcdb.adres AS new_street_address, pcdb.woonplaats AS new_city,
      pcdb.latitude AS new_latitude, pcdb.longitude AS new_longitude
    FROM civicrm_address addr
    LEFT JOIN civicrm_postcodenl pcdb
    ON pcdb.postcode_nr = SUBSTRING(addr.postal_code,1,4)
      AND pcdb.postcode_letter = SUBSTRING(addr.postal_code,6,2) COLLATE utf8_unicode_ci
      AND pcdb.huisnummer_van > 0 AND pcdb.huisnummer_tot > 0
    WHERE addr.country_id = 1152
    GROUP BY addr.id ORDER BY RAND()
        */

    // Add queries to randomize all phone numbers
    $phone = \CRM_Core_DAO::executeQuery('SELECT id, phone_type_id FROM civicrm_phone phone WHERE
      contact_id NOT IN (' . Config::CIVI_KEEP_CONTACTS . ')');
    while ($phone->fetch()) {

      if($phone->phone_type_id == 2) {
        $newNumber = $this->faker->mobilePhone;
      } else {
        $newNumber = $this->faker->homePhone;
      }

      $pquery = "UPDATE civicrm_phone SET
                  phone = '" . $this->escapeString($newNumber) . "',
                  phone_numeric = '". preg_replace('/[^0-9]/', '', $newNumber) . "'
                 WHERE id = " . (int)$phone->id;
      fputs($ffp, $pquery . ";\n");

      $this->echoCount('phone');
    }

    // Add queries to randomize all email addresses
    $email = \CRM_Core_DAO::executeQuery('SELECT id FROM civicrm_email email WHERE
      contact_id NOT IN (' . Config::CIVI_KEEP_CONTACTS . ')');
    while($email->fetch()) {

      $equery = "UPDATE civicrm_email SET
                  email = '" . $this->escapeString($this->faker->safeEmail) . "'
                 WHERE id = " . (int)$email->id;
      fputs($ffp, $equery . ";\n");

      $this->echoCount('email');
    }

    // Add queries to update all memberships
    $member = \CRM_Core_DAO::executeQuery('SELECT id, status_id FROM civicrm_membership WHERE
      contact_id NOT IN (' . Config::CIVI_KEEP_CONTACTS . ')');
    while($member->fetch()) {

      if (!in_array($member->status_id, $currentMembershipStatuses)) {
        $randomEndDate = $this->faker->dateTimeBetween('-10 years', 'now');
        $startDate = $randomEndDate->format('Y') . '-01-01';
        $endDate = $randomEndDate->format('Y-m-d');
        $joinDate = $this->faker->dateTimeBetween('-15 years', $randomEndDate)->format('Y-m-d');
      } else {
        $startDate = date('Y') . '-01-01';
        $endDate = date('Y') . '-12-31';
        $joinDate = $this->faker->dateTimeBetween('-15 years', 'now')->format('Y-m-d');
      }

      $mquery = "UPDATE civicrm_membership SET
                  start_date = '" . $startDate . "',
                  end_date = '" . $endDate . "',
                  join_date = '" . $joinDate . "',
                  source = NULL
                 WHERE id = " . (int)$member->id;
      fputs($ffp, $mquery . ";\n");

      $this->echoCount('membership');
    }

    // TODO All tables/fields below are currently hardcoded - could make this more flexible.
    // Add queries to update all bank account numbers in different tables
    // Should these be the same for a contact across all tables?
    $iban = \CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_value_iban");
    while($iban->fetch()) {
      $iquery = "UPDATE civicrm_value_iban SET
                  iban = '" . $this->escapeString($this->faker->bankAccountNumber) . "',
                  bic = '" . $this->escapeString($this->faker->swiftBicNumber) . "'
                 WHERE id = " . (int)$iban->id;
      fputs($ffp, $iquery . ";\n");
      $this->echoCount('iban');
    }

    $ibanm = \CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_value_iban_membership");
    while($ibanm->fetch()) {
      $imquery = "UPDATE civicrm_value_iban_membership SET
                  iban = '" . $this->escapeString($this->faker->bankAccountNumber) . "',
                  bic = '" . $this->escapeString($this->faker->swiftBicNumber) . "'
                 WHERE id = " . (int)$ibanm->id;
      fputs($ffp, $imquery . ";\n");
      $this->echoCount('iban_membership');
    }

    $ibanc = \CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_value_iban_contribution");
    while($ibanc->fetch()) {
      $icquery = "UPDATE civicrm_value_iban_contribution SET
                  iban = '" . $this->escapeString($this->faker->bankAccountNumber) . "',
                  bic = '" . $this->escapeString($this->faker->swiftBicNumber) . "'
                 WHERE id = " . (int)$ibanc->id;
      fputs($ffp, $icquery . ";\n");
      $this->echoCount('iban_contribution');
    }

    // Add queries to update all SEPA mandates (which also include IBANs...)
    // Adds random data for mandate date and city for now, this is probably good enough
    $sepamdt = \CRM_Core_DAO::executeQuery("SELECT id FROM civicrm_value_sepa_mandaat");
    while($sepamdt->fetch()) {
      $sepaquery = "UPDATE civicrm_value_sepa_mandaat SET
                  mandaat_datum = '" . $this->faker->dateTimeBetween('-10 years','now')->format('Y-m-d H:i:s') . "',
                  verval_datum = NULL,
                  plaats = '" . $this->faker->randomElement(['ROTTERDAM','AMSTERDAM','DEN HAAG','UTRECHT']) . "',
                  iban = '" . $this->escapeString($this->faker->bankAccountNumber) . "',
                  bic = '" . $this->escapeString($this->faker->swiftBicNumber) . "',
                  tnv = ''
                  WHERE id = " . (int)$sepamdt->id;

      fputs($ffp, $sepaquery . ";\n");
      $this->echoCount('sepa_mandate');
    }

    // TODO? Contributions are not updated yet, and could be linked to individual members.
    // TODO? Relationships, participants and activities aren't updated either.
    // TODO? Should we update actief SP/interesses? Notes are already cleared in CopyCiviDB.
    // TODO? Correct greetings, sort names, etc? Seems they're handled automatically.

    // End file
    fputs($ffp, "\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\nSET AUTOCOMMIT = 1;\nCOMMIT;\n/* End of FakerReplace randomization SQL. */\n");
    fclose($ffp);

    Util::log("SQL file to randomize contact data has been generated at {$fakefile}!", "ok");

    $run = drush_confirm("Do you want to execute these queries now?");
    if($run) {

      global $databases;
      $dbconfig = $databases;
      $dbconfig = array_shift(array_shift($dbconfig));
      if(!$dbconfig) {
        Util::log("Can't read current database configuration from $databases. Please execute the SQL file manually.", "error");
      }

      drush_print('Assuming Drupal database credentials are also valid for the CiviCRM database.');
      $dbname = drush_prompt('Please enter the CiviCRM database name (sorry for asking this twice)');
      $success = Database::runSQLFile($dbname, $dbconfig['username'], $dbconfig['password'], $fakefile);
     
      if($success) {
        Util::log("Execution finished. Please check manually if the installation contains no more sensitive data.", "ok");
        unlink($fakefile);
      } else {
        Util::log("An error occurred importing the SQL file. Please check the file manually for any errors.", "error");
      }
    } else {
      Util::log("Please manually check and run the SQL file.", "ok");
    }
  }

  /**
   * Validate arguments
   * @return bool Is valid
   */
  public function validate() {
    return TRUE;
  }


  /**
   * Function to properly escape MySQL query parameters without an active database connection
   * @param string $unescaped Unescaped string
   * @return string string Escaped string
   */
  private function escapeString($unescaped) {
    $replacements = [
      "\x00" => '\x00',
      "\n"   => '\n',
      "\r"   => '\r',
      "\\"   => '\\\\',
      "'"    => "\'",
      '"'    => '\"',
      "\x1a" => '\x1a',
    ];
    return strtr($unescaped, $replacements);
  }

  /**
   * Show a very basic progress indicator on the Drush command line using Util::log
   * @param string $type Row type
   */
  private function echoCount($type = 'contact') {

    if (!isset($this->count[$type])) {
      $this->count[$type] = 0;
    }
    $count = &$this->count[$type];

    if ($count % 10000 == 0) {
      Util::log("TESTENV: Generating queries to randomize {$type} records: {$count}.", 'ok');
    }
    $count ++;
  }

}