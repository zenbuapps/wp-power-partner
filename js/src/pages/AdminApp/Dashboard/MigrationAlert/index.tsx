import { Alert, Button } from 'antd'
import { useAtomValue, useSetAtom } from 'jotai'

import { connectionAtom, isReconnectingAtom } from '@/pages/AdminApp/Atom/atom'

/**
 * 新版安全連線遷移失敗提示
 *
 * 只在「仍走舊版共用憑證（legacy）且自動遷移失敗」時顯示。
 * 此時所有功能仍照常運作，只是請經銷商重新登入一次，讓本站換取專屬金鑰。
 *
 * 「重新登入」只切換到登入畫面，不先解除綁定（見 isReconnectingAtom）。
 */
const MigrationAlert = () => {
	const connection = useAtomValue(connectionAtom)
	const setIsReconnecting = useSetAtom(isReconnectingAtom)

	if (connection.mode !== 'legacy' || connection.migration !== 'failed') {
		return null
	}

	return (
		<Alert
			type="warning"
			showIcon
			closable
			className="mb-4"
			message="尚未完成新版安全連線，請重新登入一次以啟用專屬金鑰；目前功能不受影響。"
			description={connection.message || undefined}
			action={
				<Button
					size="small"
					type="primary"
					onClick={() => setIsReconnecting(true)}
				>
					重新登入
				</Button>
			}
		/>
	)
}

export default MigrationAlert
