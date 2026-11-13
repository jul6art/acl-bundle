# ADR-0001 — Le stockage des permissions, leur graine et leur écran

- **Statut** : Accepted — 2026-11-13 (validé d'avance par jul6art : « écris et valide le lot 2 et
  commence »)
- **Décideurs** : jul6art
- **Origine** : audit Fable 5.2, T20 (lot 2, quatrième lot du chantier de remontée)

## Contexte

acl-bundle fournit le moteur — contrats, voter, délégation, contexte de locataire — et laisse le stockage
à l'application (« la seule chose qu'acl-bundle ne peut pas fournir »). Chaque application a donc recopié
du squelette le même stockage Doctrine, sa graine et son écran :

| Classe | Rôle | Copies |
|---|---|---|
| `DoctrinePermissionStore` | écriture : rôles, dérogations par compte, `seedRole()` | 8 |
| `DoctrinePermissionSetProvider` | lecture, une fois par compte et par requête | 8 |
| `RolePermission`, `UserPermissionOverride` | les deux entités | 8 + 8 |
| `RolePermissionRepository`, `UserPermissionOverrideRepository` | leurs requêtes | 8 + 8 |
| `RolePermissionFixtures`, `SeedPermissionsCommand` (`app:permissions:seed`) | la graine | 8 + 7 |
| `RolePermissionController` + `admin/role_permission/index.html.twig` | la grille rôles × permissions | 8 |

Mesure du 2026-11-12 (`superp/docs/analyse/audit_fable_5_2_t20_copies.md`) : le lot aux écarts les plus
larges du chantier (jusqu'à 239 lignes). Relevé du code, hors commentaires, contre la référence devingame
(`lot2-diff`, 2026-11-13) :

### Les écarts, un par un

| Où | Écart | Classement |
|---|---|---|
| devingame, wtg, squelette | `seedRole()` (la graine ne pose que ce que personne n'a décidé), la commande l'appelle | **référence** |
| devinthehood, cegeta, cereezer | pas de `seedRole()` : la commande appelait `grantToRole()`, qui **repasse à « accordé » une case qu'un administrateur a décochée**. cegeta joue la graine à chaque déploiement : chaque déploiement rouvrait les refus | **dérive, et défaut** — corrigé avant le lot (report de la référence et de son test, 2026-11-13) |
| tradebot | pas de `seedRole()`, mais sa commande ne pose que les couples sans ligne (même effet) ; l'écran : un voter par case (`RolePermissionVoter::CHANGE` : on ne change que ce qu'on détient — pas d'escalade), des codes réservés au super-admin, un retour aux défauts | stockage : dérive ; écran : **besoins généraux**, remontés |
| wtg | cinq rôles dans la grille | **configuration** |
| cegeta | les **dérogations par espace** : `organization` sur `user_permission_override`, unicité (compte, espace, permission), lecture dans l'espace courant du compte ; écran réservé aux comptes de la plateforme, grille `[ROLE_USER]` ; `#[Auditable]` | **besoin du produit** : dérogations par locataire → option ; accès et rôles de l'écran → configuration ; l'audit reste à l'entité de l'application |
| cereezer | la **grille par locataire avec repli** : `organization` nullable sur `role_permission`, unicité (rôle, permission, locataire) ; la ligne d'un locataire l'emporte sur celle de la plateforme, dans les deux sens (un refus de locataire s'écrit même sans ligne) ; l'écran édite la portée de l'acteur (super-admin : les défauts) et n'écrit que les écarts ; six rôles ; fixtures de démonstration par locataire ; `#[Auditable]` | **besoin du produit** : grille par locataire → option ; portée de l'écran → configuration ; démonstration → l'application |
| devinlive | style `AbstractRepository` (`save`/`delete`/`flush`) ; le fournisseur **suit la hiérarchie des rôles** ; ni écran ni commande (la graine passe par les fixtures, via `seedRole`) | style : dérive ; hiérarchie : **option** (désactivée par défaut : l'activer ailleurs changerait des décisions) |
| superp | modèle **strictement par organisation** (lignes modèles semées dans chaque organisation, aucun repli), colonne `is_granted`, dérogations en `effect` texte (`grant`/`deny`), trois écrans (admin, organisation, API), retrait par fonctionnalité | **hors du lot** : son schéma n'est pas celui des autres, et T20 garde chaque schéma inchangé ; signalé, pas tranché (T20.3) |
| devinthecode | aucune de ces classes | non concerné |

## Décision

### 1. acl-bundle 1.2.0 livre le stockage Doctrine (phase 2a)

Sous `Doctrine/` :

- **`AbstractRolePermission`** et **`AbstractUserPermissionOverride`** — des `MappedSuperclass` : les
  colonnes de la référence (`role_code`, `permission`, `granted` ; `user_id`, `permission`, `granted`).
  Le compte de la dérogation cible `AclUserInterface`, résolu vers `acl.storage.user_class` par
  `resolve_target_entities` (comme le lot 5). **L'application garde une entité mince** : la table, ses
  unicités et index, l'association à son locataire et `#[Auditable]` — ce qu'une classe de base ne peut
  pas déclarer. Le schéma ne bouge pas.
- **`DoctrinePermissionStore`** (`PermissionStoreInterface` + `seedRole()`) et
  **`DoctrinePermissionSetProvider`** — génériques sur les classes configurées, jamais de `flush()`
  (l'appelant décide de la transaction). Les dépôts recopiés disparaissent.
- **Les locataires, en options** (lus sur `AclUserInterface::getTenant()`, association nommée
  `acl.storage.tenant_property`) :
  - `acl.storage.role_scope: global | tenant` — `tenant` : la ligne d'un locataire l'emporte sur celle de
    la plateforme (locataire nul), accordée comme refusée ; retirer chez un locataire ce que la plateforme
    accorde écrit un refus. (cereezer)
  - `acl.storage.override_scope: global | tenant` — `tenant` : une dérogation appartient au locataire
    courant du compte. (cegeta)
- **`acl.storage.role_hierarchy`** (faux par défaut) : le fournisseur lit aussi les rôles atteignables.
  (devinlive)
- **La graine** : `PermissionSeeder` (`seedRole()` sur toute la carte, jamais `grantToRole()`) et la
  commande **`acl:permissions:seed`**, alias `app:permissions:seed` (les hooks de déploiement l'appellent
  sous ce nom). La carte vient de **`PermissionCatalogInterface`** (`all()`, `grouped()`, `defaults()`,
  `superAdminOnly()`), que l'application implémente une fois sur ses `PermissionCodes` et
  `DefaultRolePermissions`. Les fixtures de l'application se réduisent à l'appel de la graine.

```yaml
acl:
    storage:
        enabled: true
        user_class: App\Entity\User
        role_permission_class: App\Entity\RolePermission
        user_permission_override_class: App\Entity\UserPermissionOverride
        catalog: App\Security\PermissionCatalog
        role_scope: global            # cereezer : tenant
        override_scope: global        # cegeta : tenant
        tenant_property: organization
        role_hierarchy: false         # devinlive : true
```

Désactivé par défaut : une application garde son stockage tant qu'elle n'active pas la clé — deux
fournisseurs se disputeraient sinon le moteur.

### 2. admin-bundle livre l'écran (phase 2b)

La grille rôles × permissions — contrôleur, gabarit, traductions de l'écran — sur le stockage et le
catalogue d'acl-bundle (dépendance optionnelle d'admin-bundle : l'écran n'existe que si acl-bundle est
là). Les besoins remontés : les rôles de la grille (configuration), l'accès à l'écran (un attribut de
sécurité configurable : cegeta le réserve à la plateforme), la portée (plateforme, ou le locataire de
l'acteur avec repli : cereezer), **la garde d'escalade par case** (tradebot : on ne coche que ce qu'on
détient ; les codes `superAdminOnly()` au super-admin seul) et **le retour aux défauts** (tradebot). Les
libellés des rôles et des ressources restent aux traductions de l'application. Aucune route déclarée :
l'application la nomme (`admin_role_permission_index`, `…_save`, `…_reset`), comme au lot 4. Le détail
s'écrit en amendement de cet ADR à l'ouverture de la phase 2b, après lecture des quatre gabarits.

### 3. Ce qui reste dans chaque application

| Application | Après le lot |
|---|---|
| devingame, wtg, devinthehood, tradebot, squelette | les deux entités minces, `PermissionCatalog`, `acl.storage` (défauts), la route de l'écran ; tradebot : rien de son voter (remonté) |
| cegeta | + `override_scope: tenant` ; ses entités minces portent `organization`, `#[Auditable]`, `#[TenantScoped]` |
| cereezer | + `role_scope: tenant` ; ses fixtures de démonstration par locataire |
| devinlive | + `role_hierarchy: true` ; pas d'écran |
| superp | inchangé (hors du lot) |

## Comportements qui changent (assumés)

- Nulle part, sauf la graine de devinthehood, cegeta et cereezer, corrigée **avant** le lot : relancée,
  elle ne rouvre plus une case refusée.

## Tests exigés

- **Bundle** : chaque méthode du stockage dans chaque portée (global ; rôles par locataire : repli,
  refus de locataire, accord de locataire ; dérogations par locataire), le fournisseur (hiérarchie), la
  graine relançable (un refus reste un refus), l'alias de la commande, la configuration (désactivée par
  défaut, classes manquantes refusées), le tout sur une vraie base ; dépendances hautes et basses ;
  PHPStan, Rector, style.
- **Chaque application** : schéma inchangé (`doctrine:schema:validate`, aucun écart de migration) ; ses
  tests existants avant et après ; `SeedPermissionsCommandTest` ; porte complète. **Dans Chrome, avant et
  après** : décocher une case de la grille et enregistrer, recharger (la case reste décochée), un compte du
  rôle se voit refuser l'écran gardé ; cereezer : la même case chez un locataire et à la plateforme.
- **Squelette** : les cinq modes générés, leur porte jouée ; la grille sur un backoffice généré.

## Conséquences

- acl-bundle 1.2.0 (le stockage, phase 2a), puis admin-bundle 1.40.0 (l'écran, phase 2b). Démonstrations
  d'abord (cereezer, tradebot, wtg, devinthehood), puis devingame, cegeta, devinlive.
- Le README d'acl-bundle cesse de dire que le stockage appartient à l'application : il appartient au
  bundle, son **schéma** (table, unicités, locataire) à l'application.
- **1.2.1** (2026-11-13, trouvé en migrant cereezer) : `saveRoleGrid()` préchargeait les lignes d'une grille
  de locataire, mais passait au niveau plateforme par `grantToRole()`/`revokeFromRole()`, une requête par
  case — six cents pour six rôles et cent codes. Une lecture dans les deux portées ; le noyau de test compte
  ses requêtes.

## Bilan de la phase 2a (2026-11-13)

- Sept applications et le squelette adoptent le stockage ; **schéma inchangé partout**
  (`doctrine:schema:update --dump-sql` : rien à faire) ; leurs tests de permissions passent avant et après.
- Dans Chrome, avant et après, à l'identique : la case décochée tient au rechargement et un compte du rôle
  reçoit 403 (devingame, wtg, tradebot), la graine relancée sous `app:permissions:seed` laisse le refus
  (devingame, devinthehood, tradebot, cegeta) ; cereezer : décocher chez Acme retire la ligne d'Acme (le
  défaut de plateforme refuse déjà), la recocher la rétablit, la plateforme ne bouge pas ; devinlive : sa
  grille et une dérogation par compte, posée puis retirée par son écran d'accès.
- Restent à l'application, comme décidé : les entités minces, `PermissionCatalog`, les fixtures réduites à
  la graine (cereezer y garde sa démonstration par locataire) ; devinlive garde son écran d'accès
  (`AccessGrid`), qui lit désormais la base par l'`EntityManager`.

## Amendement — la phase 2b : l'écran (2026-11-13, validé par jul6art : « enchaîne sur la phase 2b, je valide »)

Lecture des gabarits : la référence (devingame, wtg, squelette), devinthehood (colonne super-admin
verrouillée, libellés, largeurs fixes, barre collante et contrôleur Stimulus des changements en attente),
tradebot (légende, marque des écarts au défaut, cases non modifiables désactivées, bouton « Rétablir »),
cegeta et cereezer (défilement horizontal mesuré à 360 px, bandeau de l'organisation chez cereezer). Huit
gabarits, cinq rendus, chacun corrigé à l'écran pour une raison écrite.

**Décision** : comme au lot 4 (le sélecteur de langue), **le gabarit reste à l'application** ; admin-bundle
1.40 livre le **contrôleur** — la seule copie mesurée (`RolePermissionController`, 10 copies, 9 variantes) —
et le contrat de ce qu'il passe au gabarit : `roles`, `grouped`, `granted` (rôle → codes accordés dans la
portée), `defaults`, `superAdminOnly`, `changeable` (rôle → code → modifiable), `organization` (la portée,
ou `null`). Configuration `admin.role_permissions` :

- `roles` : les colonnes (cereezer six, wtg cinq, cegeta `ROLE_USER`) — lu aussi par les tests de vocabulaire
  (`%admin.role_permissions.roles%`) ;
- `read`, `update` : les attributs de sécurité de l'écran (les `PermissionCodes::PERMISSION_*` du produit) ;
  `access` : un attribut de plus, exigé partout (cegeta : `ROLE_SUPER_ADMIN`, la plateforme seule) ;
- `scope` : `platform` (une grille), ou `tenant` — celle de l'organisation de l'acteur, le super-admin
  éditant la plateforme ; l'enregistrement n'écrit que les écarts au défaut (cereezer) ;
- `escalation_guard` (faux par défaut : rien ne change ailleurs) : une case ne change que si l'acteur
  détient son code ; forcer l'accord d'une case verrouillée est un 403 (tradebot) ;
- `reset` : la route « Rétablir les défauts » (tradebot), qui ne touche que les cases modifiables ;
- `template`, `index_route`.

Mêmes jeton (`role_permissions`), mêmes messages flash (`permission.flash.saved`, `permission.flash.reset`,
`flash.invalid_csrf`), mêmes noms de routes : les gabarits et les tests des applications ne changent pas, sauf
pour lire les rôles depuis la configuration. Le voter de tradebot (`RolePermissionVoter`, `PermissionChange`)
part avec la copie.

## Bilan de la phase 2b (2026-11-13)

- admin-bundle 1.40.0 livre `RolePermissionController` ; six applications le routent et suppriment leur
  copie : devingame, wtg, devinthehood (plateforme), tradebot (`escalation_guard`, `reset` ; son voter
  `RolePermissionVoter` et `PermissionChange` partent avec la copie), cegeta (`access: ROLE_SUPER_ADMIN`),
  cereezer (`scope: tenant`) ; le squelette aussi (`admin_role_permissions.yaml` du mode avec comptes).
  devinlive et superp n'ont pas cette grille : ils suivent seulement la version. **Schéma inchangé partout.**
- **1.41.0**, trouvée par la porte de tradebot : l'écran se garde par configuration, sans `#[IsGranted]`, et le
  test qui vérifie que chaque entrée du menu dit la permission de son écran n'avait rien à lire. Comme
  `admin.deadlines.access` pour les échéances, `admin.role_permissions.read`, `.update` et `.access` disent
  désormais ce que le contrôleur exige, et le contrôleur est branché dessus.
- **Une clé de plus** : le `FlashKeyTest` des applications lit aussi les contrôleurs d'admin-bundle ; celui de
  la grille peut poser `permission.flash.reset`, donc chaque catalogue la porte, dans toutes ses langues
  (cegeta : cinq), même sans la route « Rétablir ».
- **La carte des workflows ne documente pas un contrôleur de `vendor/`** : les pages de la grille sortent de
  `docs/workflows/` partout. Chez devingame, le guide « Les comptes et les permissions » (tiré de ces pages)
  perd sa section « Les permissions », comme il n'en a jamais eu pour l'apparence ou les échéances ; ses deux
  scénarios orphelins partent avec elle.
- Dans Chrome, après : devingame, wtg — la case décochée tient au rechargement et un compte du rôle reçoit 403,
  puis la case revient ; tradebot — un manager qui détient `permission:update` ne modifie que les 72 cases
  dont il détient le code, forcer une case verrouillée est un 403 et ne change rien, « Rétablir » rend les 47
  cases du défaut, et sans `permission:update` les 92 cases sont désactivées et l'envoi est un 403 (revu en
  1.41.0) ; devinthehood et cegeta — la grille égale la base, une case posée puis retirée revient à l'état de
  départ ; cereezer — décocher chez Acme retire sa ligne d'élargissement, la recocher la rétablit, la grille
  de plateforme (196 cases) ne voit ni l'élargissement d'Acme ni le refus de Globex, un compte sans
  `permission:read` reçoit 403.
- **Constat, hors du lot** (cereezer) : le refus de `permission:read` posé chez Globex pour `ROLE_ADMIN` par
  les fixtures ne borne pas son administrateur — `ROLE_ADMIN` y est `tenant_admin_role`, que
  `PermissionDecisionService` laisse passer sur le chemin web après la grille, quoi qu'elle dise. Comportement
  antérieur au lot (l'ancien contrôleur de cereezer l'écrivait déjà) ; la référence de configuration, elle, dit
  « anything its tenant is not explicitly denied ». À trancher avec le porteur.

## Le constat tranché : la règle 6 lit les refus (1.3.0, 2026-11-13, décidé par jul6art : « corrige ce trou »)

- **Ce qui change** : l'administrateur de locataire est borné par ce que la grille refuse à SON rôle, dans son
  locataire. Un refus est une ligne décidée à `false`, celle du locataire avant celle de la plateforme. Une
  permission que personne n'a décidée passe toujours, pour qu'un nouveau code serve avant d'être semé. La
  surcharge du compte (règle 4) et l'octroi d'un autre de ses rôles (règle 5) passent toujours avant.
- **Seule la colonne du rôle d'administration compte.** La grille de plateforme garde ses cases décochées à
  `false`. Si un refus fait à `ROLE_USER` bornait l'administrateur qui porte aussi ce rôle, chacune de ces
  cases lui retirerait ce que son propre rôle n'a jamais refusé.
- **Le contrat est optionnel** : `RoleRefusalProviderInterface::refusedToRoleFor()`, à côté de
  `PermissionSetProviderInterface`. Le fournisseur du bundle l'implémente ; un fournisseur d'application
  (superp, devinthecode) qui ne l'implémente pas garde l'ancienne portée. Coût : une lecture par compte et par
  rôle et par requête, seulement quand une décision atteint la règle 6.
- **Effet chez les consommateurs** : seul cereezer change (`role_scope: tenant`, `ROLE_ADMIN` dans la grille ;
  le refus de `permission:read` posé chez Globex par ses fixtures prend effet). cegeta n'a pas de colonne pour
  `ROLE_ORGANIZATION_ADMIN`, ni de ligne refusée pour ce rôle. Dans les autres applications, personne ne porte
  `ROLE_TENANT_ADMIN`.
- **Ce qui reste vrai** : une case que la graine n'a jamais écrite (chez cereezer, 17 codes de `ROLE_ADMIN` :
  `organization:*`, `portal:*`, `contact-request:*`) s'affiche décochée, mais l'administrateur y passe. Les
  routes réservées à la plateforme gardent leur contrôle `isSuperAdmin()` explicite.
- **Nouveau risque** : un administrateur de locataire qui retire `permission:update` à son propre rôle perd
  l'accès à sa grille. Seul un super-admin peut alors la lui rendre.
