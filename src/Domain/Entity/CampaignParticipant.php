<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\ParticipantRole;

/**
 * Binds a Commons username to a role within a campaign.
 *
 * Deliberately keyed by username rather than by User: campaign organizers
 * and maintainers often never log into this tool, but their uploads still
 * need excluding when the disqualification rules say so.
 */
#[ORM\Entity]
#[ORM\Table(name: 'campaign_participant')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_user_role', columns: ['campaign_id', 'username', 'role'])]
#[ORM\Index(name: 'idx_participant_username', columns: ['username'])]
class CampaignParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    #[ORM\Column(type: 'string', length: 32, enumType: ParticipantRole::class)]
    private ParticipantRole $role;

    public function __construct(Campaign $campaign, string $username, ParticipantRole $role)
    {
        $this->campaign = $campaign;
        $this->username = User::canonicaliseUsername($username);
        $this->role = $role;
        $campaign->addParticipant($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getRole(): ParticipantRole
    {
        return $this->role;
    }
}
