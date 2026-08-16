<?php

namespace PauseCafe\SignIn;

/**
 * A method somebody can connect to an account they are already signed in to.
 *
 * Only outside providers can be linked. A password and an emailed link are not
 * separate identities — they are ways of proving the one this site already
 * holds, so there is nothing to connect.
 *
 * The difference between this and signing in matters more than it looks. On the
 * login page a provider is asked "who is this?", and the only answer it can
 * give about somebody it has never introduced before is an email address, which
 * is evidence rather than proof. Here it is asked nothing of the kind: the
 * person has already proved who they are with a credential this site issued,
 * and the provider is only being written down as another way back. The address
 * plays no part, and does not even have to match.
 */
interface Linkable {

	/**
	 * Finishes a flow that was started to connect a provider, not to sign in.
	 *
	 * Implementations must verify the provider's response exactly as strictly
	 * as a sign-in would, and must never fall back to matching an account by
	 * address — that fallback is the thing linking exists to avoid.
	 *
	 * @param int   $userId The signed-in person the link belongs to.
	 * @param array $input  Whatever the provider sent back.
	 */
	public function completeLink( int $userId, array $input ): Outcome;
}
