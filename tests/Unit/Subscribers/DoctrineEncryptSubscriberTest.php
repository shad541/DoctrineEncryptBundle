<?php

namespace Ambta\DoctrineEncryptBundle\Tests\Unit\Subscribers;

use Ambta\DoctrineEncryptBundle\Configuration\Encrypted;
use Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Ambta\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;
use Ambta\DoctrineEncryptBundle\Tests\Unit\Subscribers\fixtures\ExtendedUser;
use Ambta\DoctrineEncryptBundle\Tests\Unit\Subscribers\fixtures\User;
use Ambta\DoctrineEncryptBundle\Tests\Unit\Subscribers\fixtures\WithUser;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DoctrineEncryptSubscriberTest extends TestCase
{
    /**
     * @var DoctrineEncryptSubscriber
     */
    private $subscriber;

    /**
     * @var EncryptorInterface|MockObject
     */
    private $encryptor;

    /**
     * @var Reader|MockObject
     */
    private $reader;

    /** @var EntityManagerInterface|MockObject */
    private $em;

    protected function createMock($originalClassName): MockObject
    {
        $oldErrorLevel = ini_get('error_reporting');
        ini_set('error_reporting',E_ALL ^ E_DEPRECATED);

        $return = parent::createMock($originalClassName);

        ini_set('error_reporting',$oldErrorLevel);

        return $return;
    }


    protected function setUp(): void
    {
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->encryptor
            ->expects($this->any())
            ->method('encrypt')
            ->willReturnCallback(function (string $arg) {
                return 'encrypted-'.$arg;
            })
        ;
        $this->encryptor
            ->expects($this->any())
            ->method('decrypt')
            ->willReturnCallback(function (string $arg) {
                return preg_replace('/^encrypted-/', '', $arg);
            })
        ;

        $this->reader = $this->createMock(Reader::class);
        $this->reader->expects($this->any())
            ->method('getPropertyAnnotation')
            ->willReturnCallback(function (\ReflectionProperty $reflProperty, string $class) {
                if (Encrypted::class === $class) {
                    return \in_array($reflProperty->getName(), ['name', 'address', 'extra']);
                }
                if (Embedded::class === $class) {
                    return 'user' === $reflProperty->getName();
                }

                return false;
            })
        ;
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getClassMetadata')
            ->willReturnCallback(function (string $className) {
                $classMetaData = $this->createMock(ClassMetadata::class);
                $classMetaData->rootEntityName = $className;

                return $classMetaData;
            });

        $this->subscriber = new DoctrineEncryptSubscriber($this->reader, $this->encryptor);
    }

    public function testSetRestorEncryptor(): void
    {
        $replaceEncryptor = $this->createMock(EncryptorInterface::class);

        $this->assertSame($this->encryptor, $this->subscriber->getEncryptor());
        $this->subscriber->setEncryptor($replaceEncryptor);
        $this->assertSame($replaceEncryptor, $this->subscriber->getEncryptor());
        $this->subscriber->restoreEncryptor();
        $this->assertSame($this->encryptor, $this->subscriber->getEncryptor());
    }

    protected function triggerProcessFields(Object $entity,bool $encrypt)
    {
        $class  = new ReflectionClass(DoctrineEncryptSubscriber::class);
        $method = $class->getMethod('processFields');
        $method->setAccessible(true);
        $method->invokeArgs($this->subscriber,[$entity,$this->em,$encrypt]);
    }

    public function testProcessFieldsEncrypt(): void
    {
        $user = new User('David', 'Switzerland');

        $this->triggerProcessFields($user,true);

        $this->assertStringStartsWith('encrypted-', $user->name);
        $this->assertStringStartsWith('encrypted-', $user->getAddress());
    }

    public function testProcessFieldsEncryptExtend(): void
    {
        $user = new ExtendedUser('David', 'Switzerland', 'extra');

        $this->triggerProcessFields($user,true);

        $this->assertStringStartsWith('encrypted-', $user->name);
        $this->assertStringStartsWith('encrypted-', $user->getAddress());
        $this->assertStringStartsWith('encrypted-', $user->extra);
    }

    public function testProcessFieldsEncryptEmbedded(): void
    {

        $withUser = new WithUser('Thing', 'foo', new User('David', 'Switzerland'));

        $this->triggerProcessFields($withUser,true);

        $this->assertStringStartsWith('encrypted-', $withUser->name);
        $this->assertSame('foo', $withUser->foo);
        $this->assertStringStartsWith('encrypted-', $withUser->user->name);
        $this->assertStringStartsWith('encrypted-', $withUser->user->getAddress());
    }

    public function testProcessFieldsEncryptNull(): void
    {
        $user = new User('David', null);

        $this->triggerProcessFields($user,true);

        $this->assertStringStartsWith('encrypted-', $user->name);
        $this->assertNull($user->getAddress());
    }

    public function testProcessFieldsNoEncryptor(): void
    {
        $user = new User('David', 'Switzerland');

        $this->subscriber->setEncryptor(null);
        $this->triggerProcessFields($user,true);

        $this->assertSame('David', $user->name);
        $this->assertSame('Switzerland', $user->getAddress());
    }

    public function testProcessFieldsDecrypt(): void
    {
        $user = new User('encrypted-David<ENC>', 'encrypted-Switzerland<ENC>');

        $this->triggerProcessFields($user,false);

        $this->assertSame('David', $user->name);
        $this->assertSame('Switzerland', $user->getAddress());
    }

    public function testProcessFieldsDecryptExtended(): void
    {
        $user = new ExtendedUser('encrypted-David<ENC>', 'encrypted-Switzerland<ENC>', 'encrypted-extra<ENC>');

        $this->triggerProcessFields($user,false);

        $this->assertSame('David', $user->name);
        $this->assertSame('Switzerland', $user->getAddress());
        $this->assertSame('extra', $user->extra);
    }

    public function testProcessFieldsDecryptEmbedded(): void
    {
        $withUser = new WithUser('encrypted-Thing<ENC>', 'foo', new User('encrypted-David<ENC>', 'encrypted-Switzerland<ENC>'));

        $this->triggerProcessFields($withUser,false);

        $this->assertSame('Thing', $withUser->name);
        $this->assertSame('foo', $withUser->foo);
        $this->assertSame('David', $withUser->user->name);
        $this->assertSame('Switzerland', $withUser->user->getAddress());
    }

    public function testProcessFieldsDecryptNull(): void
    {
        $user = new User('encrypted-David<ENC>', null);

        $this->triggerProcessFields($user,false);

        $this->assertSame('David', $user->name);
        $this->assertNull($user->getAddress());
    }

    public function testProcessFieldsDecryptNonEncrypted(): void
    {
        // no trailing <ENC> but somethint that our mock decrypt would change if called
        $user = new User('encrypted-David', 'encrypted-Switzerland');

        $this->triggerProcessFields($user,false);

        $this->assertSame('encrypted-David', $user->name);
        $this->assertSame('encrypted-Switzerland', $user->getAddress());
    }

    /**
     * Test that fields are encrypted before flushing.
     */
    public function testOnFlush(): void
    {
        $user = new User('David', 'Switzerland');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->any())
            ->method('getScheduledEntityInsertions')
            ->willReturn([$user])
        ;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())
            ->method('getUnitOfWork')
            ->willReturn($uow)
        ;
        $classMetaData = $this->createMock(ClassMetadata::class);
        $classMetaData->rootEntityName = User::class;
        $em->method('getClassMetadata')
            ->willReturnCallback(function (string $className) {
                $classMetaData = $this->createMock(ClassMetadata::class);
                $classMetaData->rootEntityName = $className;

                return $classMetaData;
            });
        $uow->expects($this->any())->method('recomputeSingleEntityChangeSet');

        $onFlush = new OnFlushEventArgs($em);

        $this->subscriber->onFlush($onFlush);

        $this->assertStringStartsWith('encrypted-', $user->name);
        $this->assertStringStartsWith('encrypted-', $user->getAddress());
    }

    /**
     * Test that fields are decrypted again after flushing
     */
    public function testPostFlush(): void
    {
        $user = new User('encrypted-David<ENC>', 'encrypted-Switzerland<ENC>');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects($this->any())
            ->method('getIdentityMap')
            ->willReturn([[$user]])
        ;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())
            ->method('getUnitOfWork')
            ->willReturn($uow)
        ;
        $classMetaData = $this->createMock(ClassMetadata::class);
        $classMetaData->rootEntityName = User::class;
        $em->method('getClassMetadata')
            ->willReturnCallback(function (string $className) {
                $classMetaData = $this->createMock(ClassMetadata::class);
                $classMetaData->rootEntityName = $className;

                return $classMetaData;
            });

        $postFlush = new PostFlushEventArgs($em);

        $this->subscriber->postFlush($postFlush);

        $this->assertSame('David', $user->name);
        $this->assertSame('Switzerland', $user->getAddress());
    }
}