import { Alert, Button } from 'antd'
import { useAtomValue, useSetAtom } from 'jotai'

import { connectionAtom, isReconnectingAtom } from '@/pages/AdminApp/Atom/atom'

/**
 * 新版安全連線遷移失敗提示
 *
 * 只在「仍走舊版共用憑證（legacy）且需要重新登入」時顯示。
 * 文案由 PHP（ConnectionService::attention）決定：共用帳號還能用時會說「目前功能不受影響」，
 * CloudServer 停用共用帳號之後就不會，這裡不可寫死。
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
			message={
				connection.message ||
				'尚未完成新版安全連線，請重新登入一次以啟用專屬金鑰。'
			}
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
