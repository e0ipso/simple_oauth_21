<?php

namespace Drupal\Tests\simple_oauth_device_flow\Unit;

use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for DeviceCode entity.
 */
#[Group('simple_oauth_device_flow')]
class DeviceCodeEntityTest extends UnitTestCase {

  /**
   * Tests basic DeviceCode entity functionality.
   */
  public function testDeviceCodeBasicFunctionality() {
    // Mock the required services that would normally be injected.
    $this->markTestSkipped('Unit test requires full Drupal bootstrap for entity system.');
  }

  /**
   * Tests League OAuth2 interface compliance.
   */
  public function testLeagueInterfaceCompliance() {
    // Verify that our entity class implements the required interfaces.
    $this->assertTrue(
      in_array('League\OAuth2\Server\Entities\DeviceCodeEntityInterface', class_implements(DeviceCode::class)),
      'DeviceCode must implement DeviceCodeEntityInterface'
    );

    $this->assertTrue(
      in_array('League\OAuth2\Server\Entities\TokenInterface', class_implements(DeviceCode::class)),
      'DeviceCode must implement TokenInterface (via DeviceCodeEntityInterface)'
    );
  }

  /**
   * Tests required methods exist.
   */
  public function testRequiredMethodsExist() {
    $reflection = new \ReflectionClass(DeviceCode::class);

    // DeviceCodeEntityInterface methods.
    $this->assertTrue($reflection->hasMethod('getUserCode'));
    $this->assertTrue($reflection->hasMethod('setUserCode'));
    $this->assertTrue($reflection->hasMethod('getVerificationUri'));
    $this->assertTrue($reflection->hasMethod('setVerificationUri'));
    $this->assertTrue($reflection->hasMethod('getVerificationUriComplete'));
    $this->assertTrue($reflection->hasMethod('getLastPolledAt'));
    $this->assertTrue($reflection->hasMethod('setLastPolledAt'));
    $this->assertTrue($reflection->hasMethod('getInterval'));
    $this->assertTrue($reflection->hasMethod('setInterval'));
    $this->assertTrue($reflection->hasMethod('getUserApproved'));
    $this->assertTrue($reflection->hasMethod('setUserApproved'));

    // TokenInterface methods.
    $this->assertTrue($reflection->hasMethod('getIdentifier'));
    $this->assertTrue($reflection->hasMethod('setIdentifier'));
    $this->assertTrue($reflection->hasMethod('getExpiryDateTime'));
    $this->assertTrue($reflection->hasMethod('setExpiryDateTime'));
    $this->assertTrue($reflection->hasMethod('getUserIdentifier'));
    $this->assertTrue($reflection->hasMethod('setUserIdentifier'));
    $this->assertTrue($reflection->hasMethod('getClient'));
    $this->assertTrue($reflection->hasMethod('setClient'));
    $this->assertTrue($reflection->hasMethod('getScopes'));
    $this->assertTrue($reflection->hasMethod('addScope'));
  }

}
