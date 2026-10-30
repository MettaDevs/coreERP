---- MODULE CopyRetry ----
EXTENDS Naturals, TLC
VARIABLES phase, clean, nextStep, attempts, sourceModules, snapshotModules, copiedModules, restored
vars == <<phase, clean, nextStep, attempts, sourceModules, snapshotModules, copiedModules, restored>>
Init == /\ phase = "start" /\ clean = [i \in 1..7 |-> FALSE] /\ nextStep = 1 /\ attempts = 1
        /\ sourceModules = 1 /\ snapshotModules = 0 /\ copiedModules = 0 /\ restored = FALSE
Restore == /\ phase = "start" /\ restored' = TRUE /\ phase' = "disarm"
           /\ snapshotModules' = sourceModules /\ copiedModules' = sourceModules
           /\ UNCHANGED <<clean, nextStep, attempts, sourceModules>>
Disarm == /\ phase = "disarm" /\ nextStep <= 7
          /\ clean' = [clean EXCEPT ![nextStep] = TRUE] /\ nextStep' = nextStep + 1
          /\ UNCHANGED <<phase, attempts, sourceModules, snapshotModules, copiedModules, restored>>
Fail == /\ phase = "disarm" /\ nextStep > 1 /\ phase' = "degraded"
        /\ UNCHANGED <<clean, nextStep, attempts, sourceModules, snapshotModules, copiedModules, restored>>
RetryCopy == /\ phase = "degraded" /\ attempts < 2 /\ attempts' = attempts + 1
             /\ phase' = "disarm" /\ nextStep' = 1
             /\ UNCHANGED <<clean, sourceModules, snapshotModules, copiedModules, restored>>
ClickProvision == /\ phase = "degraded" /\ phase' = "active"
                  /\ UNCHANGED <<clean, nextStep, attempts, sourceModules, snapshotModules, copiedModules, restored>>
SourceChanges == /\ restored /\ sourceModules = 1 /\ sourceModules' = 2
                 /\ UNCHANGED <<phase, clean, nextStep, attempts, snapshotModules, copiedModules, restored>>
Health == /\ phase = "disarm" /\ nextStep = 8
          /\ phase' = (IF copiedModules = sourceModules THEN "active" ELSE "rejected")
          /\ UNCHANGED <<clean, nextStep, attempts, sourceModules, snapshotModules, copiedModules, restored>>
Done == /\ (phase \in {"active", "rejected"} \/ (phase = "degraded" /\ attempts = 2))
        /\ UNCHANGED vars
Next == Restore \/ Disarm \/ Fail \/ RetryCopy \/ ClickProvision \/ SourceChanges \/ Health \/ Done
ActiveIsDisarmed == phase = "active" => (\A i \in 1..7 : clean[i])
CompleteSnapshotNotRejected == ~(phase = "rejected" /\ copiedModules = snapshotModules /\ (\A i \in 1..7 : clean[i]))
RetryPreservesSnapshot == restored => copiedModules = snapshotModules
====
