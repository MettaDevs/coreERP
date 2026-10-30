---- MODULE Lease ----
EXTENDS Naturals, TLC
VARIABLES opA, opB, owner, env, leaseValid, observedExpired, retiredA, failedCallback, stolen
vars == <<opA, opB, owner, env, leaseValid, observedExpired, retiredA, failedCallback, stolen>>
Init == /\ opA = "idle" /\ opB = "idle" /\ owner = "none" /\ env = "provisioning"
        /\ leaseValid = FALSE /\ observedExpired = FALSE /\ retiredA = FALSE /\ failedCallback = FALSE /\ stolen = FALSE
StartA == /\ opA = "idle" /\ opA' = "running" /\ owner' = "A" /\ leaseValid' = TRUE
          /\ UNCHANGED <<opB, env, observedExpired, retiredA, failedCallback, stolen>>
ExpireLease == /\ opA = "running" /\ leaseValid /\ leaseValid' = FALSE
               /\ UNCHANGED <<opA, opB, owner, env, observedExpired, retiredA, failedCallback, stolen>>
ReadExpired == /\ opA = "running" /\ ~leaseValid /\ ~observedExpired /\ observedExpired' = TRUE
               /\ UNCHANGED <<opA, opB, owner, env, leaseValid, retiredA, failedCallback, stolen>>
RenewA == /\ opA = "running" /\ ~leaseValid /\ leaseValid' = TRUE
          /\ UNCHANGED <<opA, opB, owner, env, observedExpired, retiredA, failedCallback, stolen>>
TakeOver == /\ observedExpired /\ opB = "idle"
            /\ opA' = "failed" /\ opB' = "running" /\ owner' = "B"
            /\ retiredA' = TRUE /\ stolen' = leaseValid /\ leaseValid' = FALSE /\ observedExpired' = FALSE
            /\ UNCHANGED <<env, failedCallback>>
FinishB == /\ opB = "running" /\ opB' = "succeeded" /\ owner' = "none" /\ env' = "active"
           /\ UNCHANGED <<opA, leaseValid, observedExpired, retiredA, failedCallback, stolen>>
OldWorkerFails == /\ retiredA /\ ~failedCallback /\ failedCallback' = TRUE /\ env' = "degraded"
                  /\ UNCHANGED <<opA, opB, owner, leaseValid, observedExpired, retiredA, stolen>>
Done == /\ opB = "succeeded" /\ failedCallback /\ UNCHANGED vars
Next == StartA \/ ExpireLease \/ ReadExpired \/ RenewA \/ TakeOver \/ FinishB \/ OldWorkerFails \/ Done
FreshLeaseNotTaken == ~stolen
OldWorkerCannotDowngrade == ~(opB = "succeeded" /\ failedCallback /\ env = "degraded")
SingleRunningOperation == ~(opA = "running" /\ opB = "running")
====
