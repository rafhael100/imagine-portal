<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\CommercialPublishing\CommercialPublicationPermissionGate;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use PHPUnit\Framework\TestCase;

/** All members here are SYNTHETIC, not authenticated by any running Portal. */
final class CommercialPublicationPermissionGateTest extends TestCase
{
    private function m(
        string $actor,
        string $org,
        array $permissions,
        MembershipStatus $status = MembershipStatus::Active,
        OrganizationRole $role = OrganizationRole::Member,
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor), new OrganizationId($org),
            $role, $status, $permissions
        );
    }
    private function evaluate(
        ?OrganizationMembership $editor,
        ?OrganizationMembership $reviewer,
        ?OrganizationMembership $publisher,
        string $org = 'tenant-imagine',
        string $editorId = 'portal-editor',
        string $reviewerId = 'portal-reviewer',
        string $publisherId = 'portal-publisher',
    ): bool {
        return (new CommercialPublicationPermissionGate())->evaluate(
            new OrganizationId($org),
            new PortalUserId($editorId), $editor,
            new PortalUserId($reviewerId), $reviewer,
            new PortalUserId($publisherId), $publisher,
        )->allowed;
    }
    private function validMembers(): array
    {
        return [
            $this->m('portal-editor','tenant-imagine',[Permission::CommercialEditCatalog]),
            $this->m('portal-reviewer','tenant-imagine',[Permission::CommercialApproveCatalog]),
            $this->m('portal-publisher','tenant-imagine',[Permission::CommercialReservePublish]),
        ];
    }

    public function test_only_three_distinct_active_explicit_grants_can_pass(): void
    {
        self::assertTrue($this->evaluate(...$this->validMembers()));
    }
    public function test_missing_explicit_role_permission_does_not_fall_back_to_owner(): void
    {
        foreach ([0,1,2] as $index) {
            $members = $this->validMembers();
            $actors = ['portal-editor','portal-reviewer','portal-publisher'];
            $members[$index] = $this->m($actors[$index],'tenant-imagine',
                [],MembershipStatus::Active, OrganizationRole::Owner);
            self::assertFalse($this->evaluate(...$members));
        }
    }
    public function test_organizations_are_isolated_across_all_three_actors(): void
    {
        foreach ([0,1,2] as $index) {
            $members = $this->validMembers();
            $permissions = [
                Permission::CommercialEditCatalog,
                Permission::CommercialApproveCatalog,
                Permission::CommercialReservePublish,
            ];
            $actors = ['portal-editor','portal-reviewer','portal-publisher'];
            $members[$index] = $this->m($actors[$index],'tenant-other',[$permissions[$index]]);
            self::assertFalse($this->evaluate(...$members));
        }
    }
    public function test_revoked_suspended_or_invited_membership_denies_each_stage(): void
    {
        foreach ([MembershipStatus::Revoked,MembershipStatus::Suspended,MembershipStatus::Invited] as $status) {
            foreach ([0,1,2] as $index) {
                $members=$this->validMembers();
                $actors=['portal-editor','portal-reviewer','portal-publisher'];
                $permissions=[
                    Permission::CommercialEditCatalog,
                    Permission::CommercialApproveCatalog,
                    Permission::CommercialReservePublish
                ];
                $members[$index]=$this->m($actors[$index],'tenant-imagine',[$permissions[$index]],$status);
                self::assertFalse($this->evaluate(...$members),$status->value);
            }
        }
    }
    public function test_missing_membership_and_permission_substitution_are_denied(): void
    {
        foreach ([0,1,2] as $index) {
            $members=$this->validMembers();
            $members[$index]=null;
            self::assertFalse($this->evaluate(...$members));
        }
        $members=$this->validMembers();
        $members[1]=$this->m('portal-reviewer','tenant-imagine',[Permission::CommercialEditCatalog]);
        self::assertFalse($this->evaluate(...$members));
    }
    public function test_same_actor_cannot_approve_or_publish_own_content(): void
    {
        $m=$this->validMembers();
        self::assertFalse($this->evaluate(
            $m[0],$m[1],$m[2],'tenant-imagine','portal-editor','portal-editor','portal-publisher'
        ));
        self::assertFalse($this->evaluate(
            $m[0],$m[1],$m[2],'tenant-imagine','portal-editor','portal-reviewer','portal-reviewer'
        ));
    }
    public function test_missing_actor_does_not_pass_even_with_member_grant(): void
    {
        $members=$this->validMembers();
        $gate=new CommercialPublicationPermissionGate();
        self::assertFalse($gate->evaluate(
            new OrganizationId('tenant-imagine'),
            null,$members[0],new PortalUserId('portal-reviewer'),$members[1],
            new PortalUserId('portal-publisher'),$members[2]
        )->allowed);
    }
}
